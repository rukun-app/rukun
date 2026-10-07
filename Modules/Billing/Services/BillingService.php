<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\BankAccount;
use Modules\Billing\Models\Expense;
use Modules\Billing\Models\GatewayCheckout;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\ReceiptReversal;
use Modules\Billing\Models\Tariff;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\SensitiveIdentifier;
use Modules\Files\Models\StoredFile;
use Modules\Payments\Models\Payment;
use Modules\Wifi\Models\WifiPackage;
use Modules\Wifi\Services\WifiFinancialGuard;

class BillingService
{
    public function __construct(private BillingScope $scope, private BillingReport $report) {}

    public function paymentType(User $actor, string $key, array $data): array
    {
        $area = $this->area($data['area_id']);

        return $this->command($actor, $area, 'billing.manage', 'payment_type', $key, $data, function () use ($actor, $area, $data): array {
            $type = PaymentType::query()->create([...$data, 'area_id' => $area->id]);
            CommunityAudit::record('payment_type.created', $type, actorId: $actor->id);

            return ['public_id' => $type->public_id];
        });
    }

    public function tariff(User $actor, string $key, PaymentType $type, array $data): array
    {
        return $this->command($actor, Area::query()->findOrFail($type->area_id), 'billing.manage', 'tariff', $key, ['type' => $type->public_id, ...$data], function () use ($actor, $type, $data): array {
            $query = Tariff::query()->where('payment_type_id', $type->id)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $data['starts_at']));
            if (! empty($data['ends_at'])) {
                $query->where('starts_at', '<', $data['ends_at']);
            }
            abort_if($query->exists(), 409, __('billing::messages.tariff_overlap'));
            $tariff = Tariff::query()->create([...$data, 'payment_type_id' => $type->id]);
            CommunityAudit::record('tariff.created', $tariff, actorId: $actor->id);

            return ['public_id' => $tariff->public_id];
        });
    }

    public function endTariff(User $actor, string $key, Tariff $tariff, array $data): array
    {
        $type = PaymentType::query()->findOrFail($tariff->payment_type_id);

        return $this->command($actor, Area::query()->findOrFail($type->area_id), 'billing.manage', 'tariff.end', $key, ['tariff' => $tariff->public_id, ...$data], function () use ($actor, $tariff, $data): array {
            $tariff->refresh();
            abort_unless($data['ends_at'] > $tariff->starts_at->toDateString() && (! $tariff->ends_at || $data['ends_at'] <= $tariff->ends_at->toDateString()), 422);
            $tariff->update(['ends_at' => $data['ends_at']]);
            CommunityAudit::record('tariff.ended', $tariff, ['ends_at' => $data['ends_at']], $actor->id);

            return ['public_id' => $tariff->public_id];
        });
    }

    public function bankAccount(User $actor, string $key, array $data): array
    {
        $area = $this->area($data['area_id']);

        return $this->command($actor, $area, 'billing.manage', 'bank_account', $key, $data, function () use ($actor, $area, $data): array {
            $bank = BankAccount::query()->create([...$data, 'area_id' => $area->id]);
            CommunityAudit::record('bank_account.created', $bank, actorId: $actor->id);

            return ['public_id' => $bank->public_id];
        });
    }

    public function generate(User $actor, string $key, array $data, bool $managedWifi = false): array
    {
        $area = $this->area($data['area_id']);
        abort_if(str_starts_with($data['subject'] ?? '', 'engagement:'), 422);
        abort_unless($area->kind === 'rt', 422);

        return $this->command($actor, $area, 'invoices.manage', 'invoice.generate', $key, $data, function () use ($actor, $area, $data, $managedWifi): array {
            $period = $data['period'].'-01';
            $this->open($area, $period);
            $type = PaymentType::query()->where('public_id', $data['payment_type_id'])->firstOrFail();
            abort_if(! $managedWifi && WifiPackage::query()->where('payment_type_id', $type->id)->exists(), 409, __('wifi::messages.managed_invoice'));
            abort_unless(in_array($type->area_id, [$area->id, $area->parent_id], true), 422);
            $query = Household::query()->where('area_id', $area->id)->where('status', 'active');
            if (isset($data['household_ids'])) {
                $query->whereIn('public_id', $data['household_ids']);
            }
            $households = $query->orderBy('id')->limit(501)->get();
            abort_if($households->count() > 500, 422);
            if (isset($data['household_ids'])) {
                abort_unless($households->count() === count($data['household_ids']), 422);
            }
            $ids = [];
            foreach ($households as $household) {
                $natural = ['household_id' => $household->id, 'payment_type_id' => $type->id, 'period' => $period, 'subject' => $data['subject'] ?? ''];
                $invoice = Invoice::query()->where($natural)->first();
                if (! $invoice) {
                    $tariff = $this->effectiveTariff($type, $period);
                    $invoice = Invoice::query()->create([...$natural, 'area_id' => $area->id, 'tariff_id' => $tariff->id, 'amount' => $tariff->amount, 'collection_policy' => $type->collection_policy, 'fund_classification' => $type->fund_classification, 'due_date' => $data['due_date'], 'settle_by' => $data['settle_by'] ?? $data['due_date'], 'state' => $data['state'] ?? 'issued']);
                    CommunityAudit::record('invoice.created', $invoice, actorId: $actor->id);
                    if ($invoice->state === 'issued') {
                        BillingEvents::emit('invoice.created', $invoice, $household->id, [$actor->id]);
                    }
                }
                $ids[] = $invoice->public_id;
            }

            return ['invoices' => $ids];
        });
    }

    public function invoiceAction(User $actor, string $key, Invoice $invoice, string $action): array
    {
        $area = Area::query()->findOrFail($invoice->area_id);

        return $this->command($actor, $area, 'invoices.manage', 'invoice.'.$action, $key, ['invoice' => $invoice->public_id], function () use ($actor, $invoice, $area, $action): array {
            $invoice->refresh();
            $this->open($area, $invoice->period->toDateString());
            if ($action === 'issue') {
                abort_unless($invoice->state === 'draft', 409);
                $tariff = $this->effectiveTariff(PaymentType::query()->findOrFail($invoice->payment_type_id), $invoice->period->toDateString());
                $invoice->update(['state' => 'issued', 'tariff_id' => $tariff->id, 'amount' => $tariff->amount]);
                BillingEvents::emit('invoice.created', $invoice, $invoice->household_id, [$actor->id]);
            } else {
                abort_unless($invoice->state !== 'cancelled' && $this->report->paid($invoice) === 0 && GatewayBilling::reserved($invoice) === 0, 409);
                $invoice->update(['state' => 'cancelled']);
            }
            CommunityAudit::record('invoice.'.$action, $invoice, actorId: $actor->id);

            return ['public_id' => $invoice->public_id];
        });
    }

    public function cash(User $actor, string $key, array $data): array
    {
        $household = $this->household($data['household_id']);
        $area = Area::query()->findOrFail($household->area_id);

        return $this->command($actor, $area, 'receipts.create', 'receipt.cash', $key, $data, function () use ($actor, $household, $data): array {
            return $this->receipt($actor, $household, $data['amount'], $data['paid_on'], 'cash', $data['allocations'] ?? null);
        }, $household);
    }

    public function submit(User $actor, string $key, array $data): array
    {
        $household = $this->household($data['household_id']);
        $area = Area::query()->findOrFail($household->area_id);

        return $this->command($actor, $area, 'payments.manual.submit', 'submission', $key, $data, function () use ($actor, $household, $area, $data): array {
            $bank = BankAccount::query()->where('public_id', $data['destination_account_id'])->whereIn('area_id', array_filter([$area->id, $area->parent_id]))->firstOrFail();
            $proof = $this->proof($actor, $data['proof_file_id']);
            $this->allocate($household, $data['amount'], $data['transferred_at'], $data['allocations'] ?? null);
            $submission = PaymentSubmission::query()->create(['area_id' => $area->id, 'household_id' => $household->id, 'submitted_by' => $actor->id, 'amount' => $data['amount'], 'transferred_at' => $data['transferred_at'], 'destination_account_id' => $bank->id, 'proof_file_id' => $proof->id, 'note' => $data['note'] ?? null, 'allocations' => $data['allocations'] ?? null]);
            CommunityAudit::record('payment_submission.created', $submission, actorId: $actor->id);
            BillingEvents::emit('payment_submission.created', $submission, $household->id, [$actor->id]);

            return ['public_id' => $submission->public_id];
        }, $household);
    }

    public function review(User $actor, string $key, PaymentSubmission $submission, string $action, array $data): array
    {
        $permission = $action === 'approve' ? 'payments.manual.approve' : ($action === 'reject' ? 'payments.manual.reject' : 'payments.manual.submit');
        $household = Household::query()->findOrFail($submission->household_id);
        $area = Area::query()->findOrFail($submission->area_id);

        return $this->command($actor, $area, $permission, 'submission.'.$action, $key, ['submission' => $submission->public_id, ...$data], function () use ($actor, $submission, $household, $action, $data): array {
            $submission = PaymentSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($action === 'cancel') {
                abort_unless($actor->id === $submission->submitted_by, 403);
            } else {
                abort_if($actor->id === $submission->submitted_by, 403, __('billing::messages.self_review'));
            }
            if ($submission->status === 'approved' && $action === 'approve') {
                return ['public_id' => $submission->public_id, 'receipt_id' => Receipt::query()->where('submission_id', $submission->id)->firstOrFail()->public_id];
            }
            abort_unless($submission->status === 'pending', 409);
            $result = null;
            if ($action === 'approve') {
                abort_if(($data['paid_on'] ?? today()->toDateString()) < $submission->transferred_at->toDateString(), 422);
                StoredFile::query()->findOrFail($submission->proof_file_id);
                $result = $this->receipt($actor, $household, $submission->amount, $data['paid_on'] ?? today()->toDateString(), 'manual', $submission->allocations, $submission);
            }
            $submission->update(['status' => match ($action) {
                'approve' => 'approved','reject' => 'rejected',default => 'cancelled'
            }, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['review_note'] ?? null]);
            CommunityAudit::record('payment_submission.'.$submission->status, $submission, actorId: $actor->id);
            if ($action !== 'cancel') {
                BillingEvents::emit('payment_submission.'.$submission->status, $submission, $household->id, [$submission->submitted_by, $actor->id]);
            }

            return ['public_id' => $submission->public_id, 'receipt_id' => $result['public_id'] ?? null];
        }, $action === 'cancel' ? $household : null);
    }

    public function reverseReceipt(User $actor, string $key, Receipt $receipt, array $data): array
    {
        $area = Area::query()->findOrFail($receipt->area_id);

        return $this->command($actor, $area, 'receipts.reverse', 'receipt.reverse', $key, ['receipt' => $receipt->public_id, ...$data], function () use ($actor, $receipt, $area, $data): array {
            $this->open($area, $data['posted_on']);
            abort_if($data['posted_on'] < $receipt->paid_on->toDateString(), 422);
            abort_if(ReceiptReversal::query()->where('receipt_id', $receipt->id)->exists(), 409);
            abort_if($receipt->gateway_payment_id !== null, 409, __('billing::messages.gateway_reversal'));
            WifiFinancialGuard::receipt($receipt->id);
            $reversal = ReceiptReversal::query()->create(['receipt_id' => $receipt->id, 'area_id' => $area->id, 'posted_on' => $data['posted_on'], 'reason' => $data['reason'], 'created_by' => $actor->id]);
            foreach (LedgerEntry::query()->where('receipt_id', $receipt->id)->whereNull('reverses_id')->get() as $entry) {
                $this->reverseEntry($actor, $entry, $data);
            }
            CommunityAudit::record('receipt.reversed', $reversal, ['receipt_public_id' => $receipt->public_id], $actor->id);

            return ['public_id' => $reversal->public_id];
        });
    }

    public function expense(User $actor, string $key, array $data): array
    {
        $area = $this->area($data['area_id']);

        return $this->command($actor, $area, 'expenses.manage', 'expense', $key, $data, function () use ($actor, $area, $data): array {
            $proof = isset($data['proof_file_id']) ? $this->proof($actor, $data['proof_file_id']) : null;
            $expense = Expense::query()->create([...$data, 'area_id' => $area->id, 'proof_file_id' => $proof?->id, 'created_by' => $actor->id]);
            CommunityAudit::record('expense.created', $expense, actorId: $actor->id);

            return ['public_id' => $expense->public_id];
        });
    }

    public function expenseAction(User $actor, string $key, Expense $expense, string $action, array $data): array
    {
        $area = Area::query()->findOrFail($expense->area_id);

        return $this->command($actor, $area, $action === 'approve' ? 'expenses.approve' : 'expenses.post', 'expense.'.$action, $key, ['expense' => $expense->public_id, ...$data], function () use ($actor, $expense, $area, $action, $data): array {
            $expense->refresh();
            if ($action === 'approve') {
                abort_if($expense->created_by === $actor->id, 403, __('billing::messages.self_review'));
                abort_unless($expense->status === 'draft', 409);
                $expense->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                abort_unless($expense->status === 'approved', 409);
                $this->open($area, $data['posted_on']);
                $expense->update(['status' => 'posted', 'posted_on' => $data['posted_on']]);
                $this->ledger($actor, $area->id, $data['posted_on'], 'expense', -$expense->amount, $expense->fund_classification, $expense->channel, ['expense_id' => $expense->id]);
            }
            CommunityAudit::record('expense.'.$expense->status, $expense, actorId: $actor->id);

            return ['public_id' => $expense->public_id];
        });
    }

    public function journal(User $actor, string $key, array $data): array
    {
        $area = $this->area($data['area_id']);

        return $this->command($actor, $area, 'ledger.adjust', 'journal', $key, $data, function () use ($actor, $area, $data): array {
            $this->open($area, $data['posted_on']);
            abort_if($data['kind'] === 'transfer' && $data['amount'] <= 0, 422);
            $group = $data['kind'] === 'transfer' ? (string) Str::uuid() : null;
            $entry = $this->ledger($actor, $area->id, $data['posted_on'], $data['kind'], $data['kind'] === 'transfer' ? -abs($data['amount']) : $data['amount'], $data['fund_classification'], $data['channel'], ['reason' => $data['reason'], 'transfer_group' => $group]);
            if ($data['kind'] === 'transfer') {
                abort_if($data['channel'] === $data['destination_channel'], 422);
                $this->ledger($actor, $area->id, $data['posted_on'], 'transfer', abs($data['amount']), $data['fund_classification'], $data['destination_channel'], ['reason' => $data['reason'], 'transfer_group' => $group]);
            }
            CommunityAudit::record('ledger.'.$data['kind'], $entry, actorId: $actor->id);

            return ['public_id' => $entry->public_id];
        });
    }

    public function reverseJournal(User $actor, string $key, LedgerEntry $entry, array $data): array
    {
        $area = Area::query()->findOrFail($entry->area_id);

        return $this->command($actor, $area, 'ledger.adjust', 'journal.reverse', $key, ['entry' => $entry->public_id, ...$data], function () use ($actor, $entry, $area, $data): array {
            abort_unless(in_array($entry->kind, ['expense', 'adjustment', 'transfer'], true), 422);
            abort_if($entry->gateway_settlement_id !== null, 409, __('billing::messages.gateway_reversal'));
            WifiFinancialGuard::ledger($entry->id);
            $this->open($area, $data['posted_on']);
            if ($entry->kind === 'transfer') {
                foreach (LedgerEntry::query()->where('transfer_group', $entry->transfer_group)->where('kind', 'transfer')->orderBy('id')->get() as $leg) {
                    $reversal = $this->reverseEntry($actor, $leg, $data);
                }
            } else {
                $reversal = $this->reverseEntry($actor, $entry, $data);
            }
            CommunityAudit::record('ledger.reversed', $reversal, actorId: $actor->id);

            return ['public_id' => $reversal->public_id];
        });
    }

    public function close(User $actor, string $key, array $data): array
    {
        $area = $this->area($data['area_id']);

        return $this->command($actor, $area, 'periods.close', 'period.close', $key, $data, function () use ($actor, $area, $data): array {
            $period = $data['period'].'-01';
            abort_if($period > today()->startOfMonth()->toDateString(), 422);
            $existing = AccountingPeriod::query()->where('area_id', $area->id)->where('period', $period)->first();
            if ($existing) {
                return ['public_id' => $existing->public_id, 'report' => $existing->report];
            }
            $this->open($area, $period);
            $areaIds = Area::query()->where('id', $area->id)->orWhere('parent_id', $area->id)->pluck('id');
            abort_if(GatewayCheckout::query()->whereIn('area_id', $areaIds)->whereIn('status', ['reserved', 'review'])->exists(), 409, __('billing::messages.gateway_reserved'));
            $report = $this->report->monthly($area, $data['period']);
            $closed = AccountingPeriod::query()->create(['area_id' => $area->id, 'period' => $period, 'report' => $report, 'closed_by' => $actor->id, 'closed_at' => now()]);
            CommunityAudit::record('accounting_period.closed', $closed, actorId: $actor->id);

            return ['public_id' => $closed->public_id, 'report' => $report];
        });
    }

    /** Called only by the verified gateway handler while holding the area's financial lock. */
    public function gatewayReceipt(GatewayCheckout $checkout, Payment $payment): Receipt
    {
        $actor = User::query()->findOrFail($checkout->actor_id);
        $household = Household::query()->findOrFail($checkout->household_id);
        $invoice = Invoice::query()->findOrFail($checkout->invoice_id);
        $result = $this->receipt($actor, $household, $checkout->amount, $payment->paid_at->toDateString(), 'gateway', [['invoice_id' => $invoice->public_id, 'amount' => $checkout->amount]], checkout: $checkout, payment: $payment);

        return Receipt::query()->where('public_id', $result['public_id'])->firstOrFail();
    }

    private function receipt(User $actor, Household $household, int $amount, string $paidOn, string $channel, ?array $explicit, ?PaymentSubmission $submission = null, ?GatewayCheckout $checkout = null, ?Payment $payment = null): array
    {
        $area = Area::query()->findOrFail($household->area_id);
        $this->open($area, $paidOn);
        $allocations = $this->allocate($household, $amount, $paidOn, $explicit, $checkout?->id);
        $receipt = Receipt::query()->create(['number' => 'R-'.Str::upper((string) Str::ulid()), 'area_id' => $area->id, 'household_id' => $household->id, 'amount' => $amount, 'paid_on' => $paidOn, 'channel' => $channel, 'submission_id' => $submission?->id, 'created_by' => $actor->id, 'gateway_payment_id' => $payment?->id, 'paid_at' => $payment?->paid_at]);
        $funds = [];
        foreach ($allocations as [$invoice,$allocated]) {
            DB::connection('rukun')->table('receipt_allocations')->insert(['receipt_id' => $receipt->id, 'invoice_id' => $invoice->id, 'amount' => $allocated]);
            $funds[$invoice->fund_classification] = ($funds[$invoice->fund_classification] ?? 0) + $allocated;
        }
        foreach ($funds as $fund => $value) {
            $this->ledger($actor, $area->id, $paidOn, 'income', $value, $fund, $channel === 'cash' ? 'cash' : 'bank', ['receipt_id' => $receipt->id]);
        }
        CommunityAudit::record('receipt.created', $receipt, actorId: $actor->id);
        BillingEvents::emit('receipt.created', $receipt, $household->id, [$actor->id]);

        return ['public_id' => $receipt->public_id, 'number' => $receipt->number];
    }

    private function allocate(Household $household, int $amount, string $paidOn, ?array $explicit, ?int $checkoutId = null): array
    {
        $period = substr($paidOn, 0, 7).'-01';
        $query = Invoice::query()->where('household_id', $household->id)->where('state', 'issued');
        $result = [];
        $remaining = $amount;
        if ($explicit !== null) {
            abort_unless(array_sum(array_column($explicit, 'amount')) === $amount, 422, __('billing::messages.allocation_total'));
            abort_unless(count(array_unique(array_column($explicit, 'invoice_id'))) === count($explicit), 422);
            foreach ($explicit as $allocation) {
                $invoice = (clone $query)->where('public_id', $allocation['invoice_id'])->lockForUpdate()->firstOrFail();
                abort_if($allocation['amount'] > $invoice->amount - $this->report->paid($invoice) - GatewayBilling::reserved($invoice, $checkoutId), 409, __('billing::messages.overpay'));
                $result[] = [$invoice, (int) $allocation['amount']];
            }
        } else {
            $invoices = $query->where('period', '<=', $period)->orderByRaw("CASE WHEN collection_policy='must_settle_in_period' AND period=? THEN 0 ELSE 1 END", [$period])->orderBy('period')->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            foreach ($invoices as $invoice) {
                $value = min($remaining, $invoice->amount - $this->report->paid($invoice) - GatewayBilling::reserved($invoice, $checkoutId));
                if ($value > 0) {
                    $result[] = [$invoice, $value];
                    $remaining -= $value;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            abort_if($remaining !== 0, 409, __('billing::messages.overpay'));
        }

        return $result;
    }

    private function ledger(User $actor, int $areaId, string $date, string $kind, int $amount, string $fund, string $channel, array $extra = []): LedgerEntry
    {
        return LedgerEntry::query()->create(['area_id' => $areaId, 'posted_on' => $date, 'kind' => $kind, 'amount' => $amount, 'fund_classification' => $fund, 'channel' => $channel, 'created_by' => $actor->id, ...$extra]);
    }

    private function reverseEntry(User $actor, LedgerEntry $entry, array $data): LedgerEntry
    {
        abort_if($data['posted_on'] < $entry->posted_on->toDateString(), 422);
        abort_if(LedgerEntry::query()->where('reverses_id', $entry->id)->exists(), 409);

        return $this->ledger($actor, $entry->area_id, $data['posted_on'], 'reversal', -$entry->amount, $entry->fund_classification, $entry->channel, ['reverses_id' => $entry->id, 'reason' => $data['reason'], 'receipt_id' => $entry->receipt_id, 'expense_id' => $entry->expense_id]);
    }

    private function effectiveTariff(PaymentType $type, string $date): Tariff
    {
        return Tariff::query()->where('payment_type_id', $type->id)->where('starts_at', '<=', $date)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $date))->firstOrFail();
    }

    private function proof(User $actor, string $id): StoredFile
    {
        DB::connection('rukun')->table(config('database.connections.core.prefix').'files')->where('public_id', $id)->where('owner_id', $actor->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
        $file = StoredFile::query()->where('public_id', $id)->where('owner_id', $actor->id)->firstOrFail();
        abort_unless(in_array($file->mime_type, ['image/jpeg', 'image/png', 'application/pdf'], true), 422);

        abort_if(DB::connection('rukun')->table('civic_documents')->where('file_id', $file->id)->exists() || DB::connection('rukun')->table('engagement_documents')->where('file_id', $file->id)->exists(), 409);

        return $file;
    }

    private function open(Area $area, string $date): void
    {
        abort_if(AccountingPeriod::query()->whereIn('area_id', array_filter([$area->id, $area->parent_id]))->where('period', '>=', substr($date, 0, 7).'-01')->exists(), 409, __('billing::messages.period_closed'));
    }

    private function area(string $id): Area
    {
        return Area::query()->where('public_id', $id)->firstOrFail();
    }

    private function household(string $id): Household
    {
        return Household::query()->where('public_id', $id)->firstOrFail();
    }

    private function command(User $actor, Area $area, string $permission, string $operation, string $key, array $data, callable $callback, ?Household $household = null): array
    {
        try {
            return DB::connection('rukun')->transaction(function () use ($actor, $area, $permission, $operation, $key, $data, $callback, $household): array {
                Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
                DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
                $actor->refresh();
                if ($household) {
                    $this->scope->household($actor, $permission, $household->fresh());
                } else {
                    $this->scope->area($actor, $permission, $area);
                }
                $fingerprint = SensitiveIdentifier::fingerprint(json_encode($data, JSON_THROW_ON_ERROR));
                $identity = ['actor_id' => $actor->id, 'operation' => $operation, 'request_key' => $key];
                $previous = DB::connection('rukun')->table('billing_requests')->where($identity)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, __('api.errors.idempotency_conflict'));

                    return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
                }
                $result = $callback();
                DB::connection('rukun')->table('billing_requests')->insert([...$identity, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['resource' => __('billing::messages.duplicate')]);
        }
    }
}
