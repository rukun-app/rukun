<?php

namespace Modules\Wifi\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Community\Models\Area;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Modules\Wifi\Models\WifiBill;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiFinance;
use Modules\Wifi\Models\WifiPackage;

class SettlementService
{
    public function context(WifiBill $bill): array
    {
        $customer = WifiCustomer::query()->findOrFail($bill->customer_id);

        return [$customer, Invoice::query()->findOrFail($bill->invoice_id), Area::query()->findOrFail($customer->area_id), WifiPackage::query()->findOrFail($customer->package_id)];
    }

    public function active(): Builder
    {
        return WifiFinance::query()->where('kind', '!=', 'reverse')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wifi_finance as reversal')->whereColumn('reversal.reverses_id', 'wifi_finance.id'));
    }

    public function paid(Invoice $invoice, string $asOf): int
    {
        return (int) $this->receipts($invoice, $asOf)->sum('a.amount');
    }

    public function eligible(WifiBill $bill): bool
    {
        [, $invoice] = $this->context($bill);

        return $invoice->state === 'issued' && today()->greaterThan($invoice->settle_by) && $this->paid($invoice, $invoice->settle_by->toDateString()) === $invoice->amount;
    }

    public function summary(WifiBill $bill): array
    {
        [, $invoice] = $this->context($bill);
        $remit = $this->active()->where('bill_id', $bill->id)->where('kind', 'remit')->first();
        $recovered = $remit ? (int) $this->active()->where('parent_id', $remit->id)->where('kind', 'recover')->sum('amount') : 0;

        return ['bill_id' => $bill->public_id, 'invoice_id' => $invoice->public_id, 'settle_by' => $invoice->settle_by->toDateString(), 'cutoff_closed' => today()->greaterThan($invoice->settle_by), 'eligible' => $this->eligible($bill), 'invoice_amount' => $invoice->amount, 'paid_at_cutoff' => $this->paid($invoice, $invoice->settle_by->toDateString()), 'remittance_id' => $remit?->public_id, 'remitted' => $remit?->amount ?? 0, 'receipt_funded' => $remit ? $remit->amount - $remit->advance : 0, 'advance_issued' => $remit?->advance ?? 0, 'advance_recovered' => $recovered, 'advance_outstanding' => ($remit?->advance ?? 0) - $recovered];
    }

    public function write(User $actor, string $key, WifiBill $bill, string $action, array $data, ?WifiFinance $original = null): array
    {
        [, , $area] = $this->context($bill);

        return app(WifiTransaction::class)->run($actor, $area, 'finance.'.$action, $key, ['bill_id' => $bill->public_id, 'original_id' => $original?->public_id, ...$data], function () use ($actor, $area): void {
            abort_unless(app(ScopeResolver::class)->allows($actor, 'wifi.settle', $area), 403);
        }, function () use ($actor, $area, $bill, $action, $data, $original): array {
            [, $invoice, , $package] = $this->context($bill);
            $date = $data['posted_on'];
            abort_if($date > today()->toDateString() || $date < $invoice->period->toDateString(), 422);
            abort_if(AccountingPeriod::query()->whereIn('area_id', array_filter([$area->id, $area->parent_id]))->where('period', '>=', substr($date, 0, 7).'-01')->exists(), 409, __('billing::messages.period_closed'));
            $base = ['bill_id' => $bill->id, 'kind' => $action, 'posted_on' => $date, 'channel' => $data['channel'] ?? $original?->channel, 'reference' => $data['reference'], 'created_by' => $actor->id];
            if ($action === 'reverse') {
                abort_unless($original && $original->bill_id === $bill->id && $this->active()->whereKey($original->id)->exists(), 409);
                abort_if($date < $original->posted_on->toDateString() || $this->active()->where('parent_id', $original->id)->exists(), 409);
                $record = WifiFinance::query()->create([...$base, 'amount' => $original->amount, 'advance' => $original->advance, 'reverses_id' => $original->id]);
                $ids = DB::connection('rukun')->table('wifi_finance_ledger')->where('finance_id', $original->id)->pluck('ledger_id');
                foreach (LedgerEntry::query()->whereIn('id', $ids)->get() as $entry) {
                    $this->post($actor, $area, $record, -$entry->amount, $entry->fund_classification, 'reversal', ['reverses_id' => $entry->id]);
                }
            } else {
                abort_unless($invoice->state === 'issued', 409);
                $remit = $this->active()->where('bill_id', $bill->id)->where('kind', 'remit')->first();
                if ($action === 'remit') {
                    abort_if($remit !== null, 409, __('wifi::messages.already_remitted'));
                    abort_unless(today()->greaterThan($invoice->settle_by) && $date >= $invoice->period->format('Y-m').'-'.sprintf('%02d', $package->remit_day) && $date >= $invoice->settle_by->toDateString(), 409);
                    $funded = $this->paid($invoice, $date);
                    $advance = $invoice->amount - $funded;
                    abort_if($advance > 0 && ! $package->allow_advance, 409, __('wifi::messages.advance_disabled'));
                    $record = WifiFinance::query()->create([...$base, 'amount' => $invoice->amount, 'advance' => $advance]);
                    $this->sources($record, $invoice, $funded);
                    $this->post($actor, $area, $record, -$funded, 'pass_through', 'expense');
                    $this->post($actor, $area, $record, -$advance, 'operational', 'adjustment');
                } else {
                    abort_unless($remit && $date >= $remit->posted_on->toDateString(), 409);
                    $outstanding = $remit->advance - (int) $this->active()->where('parent_id', $remit->id)->where('kind', 'recover')->sum('amount');
                    abort_unless($data['amount'] > 0 && $data['amount'] <= $outstanding, 409);
                    $record = WifiFinance::query()->create([...$base, 'parent_id' => $remit->id, 'amount' => $data['amount']]);
                    $this->sources($record, $invoice, $record->amount);
                    $group = (string) Str::uuid();
                    $this->post($actor, $area, $record, -$record->amount, 'pass_through', 'transfer', ['transfer_group' => $group]);
                    $this->post($actor, $area, $record, $record->amount, 'operational', 'transfer', ['transfer_group' => $group]);
                }
            }
            CommunityAudit::record('wifi.finance.'.$action, $record, ['bill_id' => $bill->public_id], $actor->id);

            return ['public_id' => $record->public_id, ...$this->summary($bill)];
        });
    }

    private function receipts(Invoice $invoice, string $date): \Illuminate\Database\Query\Builder
    {
        return DB::connection('rukun')->table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $invoice->id)->where('r.paid_on', '<=', $date)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('receipt_reversals as v')->whereColumn('v.receipt_id', 'r.id'));
    }

    private function sources(WifiFinance $record, Invoice $invoice, int $amount): void
    {
        $remaining = $amount;
        foreach ($this->receipts($invoice, $record->posted_on->toDateString())->orderBy('r.id')->select('r.id', 'a.amount')->get() as $receipt) {
            $used = DB::connection('rukun')->table('wifi_finance_receipts')->where('receipt_id', $receipt->id)->whereIn('finance_id', $this->active()->where('bill_id', $record->bill_id)->select('id'))->sum('amount');
            $take = min($remaining, $receipt->amount - $used);
            if ($take > 0) {
                DB::connection('rukun')->table('wifi_finance_receipts')->insert(['finance_id' => $record->id, 'receipt_id' => $receipt->id, 'amount' => $take]);
                $remaining -= $take;
            }
        }
        abort_unless($remaining === 0, 409, __('wifi::messages.recovery_source'));
    }

    private function post(User $actor, Area $area, WifiFinance $record, int $amount, string $fund, string $kind, array $extra = []): void
    {
        if ($amount === 0) {
            return;
        }
        $entry = LedgerEntry::query()->create(['area_id' => $area->id, 'posted_on' => $record->posted_on, 'kind' => $kind, 'amount' => $amount, 'fund_classification' => $fund, 'channel' => $record->channel, 'reason' => 'WiFi '.$record->kind.': '.$record->reference, 'created_by' => $actor->id, ...$extra]);
        DB::connection('rukun')->table('wifi_finance_ledger')->insert(['finance_id' => $record->id, 'ledger_id' => $entry->id]);
    }
}
