<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\GatewayCheckout;
use Modules\Billing\Models\GatewaySettlement;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\Receipt;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\CommunityAudit;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentManager;

class GatewayBilling
{
    public function __construct(private BillingScope $scope, private BillingReport $report, private PaymentManager $payments) {}

    public static function reserved(Invoice $invoice, ?int $except = null): int
    {
        return (int) GatewayCheckout::query()->where('invoice_id', $invoice->id)->whereIn('status', ['reserved', 'review'])->when($except, fn ($q) => $q->where('id', '!=', $except))->sum('amount');
    }

    public function checkout(User $actor, Invoice $invoice, string $key): GatewayCheckout
    {
        $checkout = DB::connection('rukun')->transaction(function () use ($actor, $invoice, $key): GatewayCheckout {
            $area = Area::query()->findOrFail($invoice->area_id);
            $this->lock($area);
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $this->scope->household($actor, 'payments.gateway.create', Household::query()->findOrFail($invoice->household_id));
            $previous = GatewayCheckout::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($previous) {
                abort_unless($previous->invoice_id === $invoice->id, 409, __('api.errors.idempotency_conflict'));

                return $previous;
            }
            $invoice->refresh();
            $this->assertOpen($area, today()->toDateString());
            abort_unless($invoice->state === 'issued', 409);
            abort_if(self::reserved($invoice) > 0, 409, __('billing::messages.gateway_reserved'));
            $amount = $invoice->amount - $this->report->paid($invoice);
            abort_if($amount <= 0, 409, __('billing::messages.overpay'));
            $payment = $this->payments->prepare($actor, 'billing.gateway', $invoice->public_id, $amount, connection: 'rukun');
            $payment->update(['expires_at' => now()->addMinutes(15)]);
            $checkout = GatewayCheckout::query()->create(['invoice_id' => $invoice->id, 'area_id' => $area->id, 'household_id' => $invoice->household_id, 'actor_id' => $actor->id, 'payment_id' => $payment->id, 'request_key' => $key, 'amount' => $amount, 'expires_at' => $payment->expires_at]);
            CommunityAudit::record('gateway.checkout_reserved', $checkout, actorId: $actor->id);

            return $checkout;
        }, 3);

        // Reservation and core order commit before calling the provider, so crashes remain recoverable.
        try {
            $this->payments->start(Payment::query()->findOrFail($checkout->payment_id), ['name' => $actor->name, 'email' => $actor->email, 'phone' => $actor->phone]);
        } catch (PaymentGatewayException) {
            // An ambiguous provider result keeps the reservation; reconciliation uses the same order ID.
        }
        $this->synchronize(Payment::query()->findOrFail($checkout->payment_id));

        return $checkout->refresh();
    }

    public function synchronize(Payment $payment): void
    {
        $checkout = GatewayCheckout::query()->where('payment_id', $payment->id)->first();
        if (! $checkout) {
            return;
        }
        DB::connection('rukun')->transaction(function () use ($checkout, $payment): void {
            $area = Area::query()->findOrFail($checkout->area_id);
            $this->lock($area);
            $checkout->refresh();
            DB::connection('rukun')->table(config('database.connections.core.prefix').'payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            $payment->refresh();
            if ($checkout->receipt_id) {
                if (in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Chargeback, PaymentStatus::PartialChargeback], true)) {
                    $checkout->update(['review_reason' => $payment->status->value]);
                }

                return;
            }
            if (in_array($payment->status, [PaymentStatus::Denied, PaymentStatus::Cancelled, PaymentStatus::Expired, PaymentStatus::Failed], true)) {
                if ($checkout->status !== 'released') {
                    $checkout->update(['status' => 'released', 'review_reason' => null]);
                    CommunityAudit::record('gateway.reservation_released', $checkout, ['provider_status' => $payment->status->value], $checkout->actor_id);
                }

                return;
            }
            if (! $payment->status->isPaid()) {
                return;
            }
            $invoice = Invoice::query()->findOrFail($checkout->invoice_id);
            $reason = match (true) {
                $checkout->status === 'released' => 'late_success_after_release',
                $payment->payment_type !== 'qris' => 'unexpected_payment_method',
                ! $payment->paid_at => 'missing_payment_time',
                $payment->amount !== $checkout->amount => 'amount_mismatch',
                $invoice->state !== 'issued' || $invoice->amount - $this->report->paid($invoice) < $checkout->amount => 'allocation_conflict',
                $this->closed($area, $payment->paid_at?->toDateString() ?? today()->toDateString()) => 'closed_period',
                default => null,
            };
            if ($reason) {
                // Released orders must not acquire a second reservation over a newer checkout.
                $checkout->update(['status' => $checkout->status === 'released' ? 'released' : 'review', 'review_reason' => $reason]);
                CommunityAudit::record('gateway.reconciliation_required', $checkout, ['reason' => $reason], $checkout->actor_id);

                return;
            }
            $receipt = app(BillingService::class)->gatewayReceipt($checkout, $payment);
            $checkout->update(['status' => 'completed', 'receipt_id' => $receipt->id, 'review_reason' => null]);
            CommunityAudit::record('gateway.receipt_posted', $checkout, ['receipt_id' => $receipt->public_id], $checkout->actor_id);
        }, 3);
    }

    public function reconcile(GatewayCheckout $checkout): GatewayCheckout
    {
        $payment = Payment::query()->findOrFail($checkout->payment_id);
        if ($payment->status === PaymentStatus::Creating) {
            if ($checkout->expires_at->isPast()) {
                Payment::query()->whereKey($payment->id)->where('status', PaymentStatus::Creating->value)->update(['status' => PaymentStatus::Failed->value]);
                $this->synchronize($payment->refresh());

                return $checkout->refresh();
            }
            $this->payments->start($payment, ['name' => $payment->user->name, 'email' => $payment->user->email]);
        }
        // Apply any already-committed success even if the status API is temporarily unavailable.
        $this->synchronize($payment);
        if (! $checkout->refresh()->receipt_id) {
            $this->synchronize($this->payments->reconcile($payment));
        }

        return $checkout->refresh();
    }

    public function settle(User $actor, GatewayCheckout $checkout, string $key, array $data): GatewaySettlement
    {
        return DB::connection('rukun')->transaction(function () use ($actor, $checkout, $key, $data): GatewaySettlement {
            $area = Area::query()->findOrFail($checkout->area_id);
            $this->lock($area);
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $this->scope->area($actor, 'payments.gateway.reconcile', $area);
            $checkout->refresh();
            $existing = GatewaySettlement::query()->where('checkout_id', $checkout->id)->first();
            if ($existing) {
                abort_unless($existing->actor_id === $actor->id && $existing->request_key === $key && $existing->fee === $data['fee'] && $existing->settled_on->toDateString() === $data['settled_on'] && $existing->statement_reference === $data['statement_reference'], 409, __('api.errors.idempotency_conflict'));

                return $existing;
            }
            abort_if(GatewaySettlement::query()->where('actor_id', $actor->id)->where('request_key', $key)->exists(), 409);
            abort_unless($checkout->status === 'completed' && $checkout->receipt_id && ! $checkout->review_reason, 409);
            $corePayment = DB::connection('rukun')->table(config('database.connections.core.prefix').'payments')->where('id', $checkout->payment_id)->lockForUpdate()->firstOrFail();
            abort_unless($corePayment->status === PaymentStatus::Paid->value, 409);
            $receipt = Receipt::query()->findOrFail($checkout->receipt_id);
            abort_if($data['fee'] > $checkout->amount || $data['settled_on'] < $receipt->paid_on->toDateString(), 422);
            $this->assertOpen($area, $data['settled_on']);
            $settlement = GatewaySettlement::query()->create(['checkout_id' => $checkout->id, 'gross' => $checkout->amount, 'fee' => $data['fee'], 'net' => $checkout->amount - $data['fee'], 'settled_on' => $data['settled_on'], 'statement_reference' => $data['statement_reference'], 'actor_id' => $actor->id, 'request_key' => $key]);
            if ($data['fee'] > 0) {
                LedgerEntry::query()->create(['area_id' => $area->id, 'posted_on' => $data['settled_on'], 'kind' => 'expense', 'amount' => -$data['fee'], 'fund_classification' => 'operational', 'channel' => 'bank', 'reason' => 'Gateway fee '.$settlement->public_id, 'gateway_settlement_id' => $settlement->id, 'created_by' => $actor->id]);
            }
            CommunityAudit::record('gateway.settlement_recorded', $settlement, ['checkout_id' => $checkout->public_id], $actor->id);

            return $settlement;
        }, 3);
    }

    public function assertOpen(Area $area, string $date): void
    {
        abort_if($this->closed($area, $date), 409, __('billing::messages.period_closed'));
    }

    private function closed(Area $area, string $date): bool
    {
        return AccountingPeriod::query()->whereIn('area_id', array_filter([$area->id, $area->parent_id]))->where('period', '>=', substr($date, 0, 7).'-01')->exists();
    }

    private function lock(Area $area): void
    {
        Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
    }
}
