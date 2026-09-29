<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Community\Models\Area;

class BillingReport
{
    public function paid(Invoice $invoice, ?string $asOf = null): int
    {
        $query = DB::connection('rukun')->table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $invoice->id);
        if ($asOf) {
            $query->where('r.paid_on', '<=', $asOf);
        }
        $query->whereNotExists(function ($query) use ($asOf): void {
            $query->selectRaw('1')->from('receipt_reversals as v')->whereColumn('v.receipt_id', 'r.id');
            if ($asOf) {
                $query->where('v.posted_on', '<=', $asOf);
            }
        });

        return (int) $query->sum('a.amount');
    }

    public function invoice(Invoice $invoice): array
    {
        $paid = $this->paid($invoice);
        $status = $invoice->state;
        if ($status === 'issued') {
            $status = $paid >= $invoice->amount ? 'paid' : ($paid > 0 ? 'partially_paid' : ($invoice->due_date->isBefore(today()) ? 'overdue' : 'issued'));
        }

        return ['paid_amount' => $paid, 'outstanding_amount' => $invoice->state === 'cancelled' ? 0 : $invoice->amount - $paid, 'status' => $status, 'overdue' => $invoice->state === 'issued' && $paid < $invoice->amount && $invoice->due_date->isBefore(today())];
    }

    public function monthly(Area $area, string $period): array
    {
        $start = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $end = $start->copy()->endOfMonth()->toDateString();
        $ids = Area::query()->where('id', $area->id)->orWhere('parent_id', $area->id)->pluck('id');
        $groups = [];
        foreach (['operational', 'pass_through'] as $fund) {
            foreach (['cash', 'bank'] as $channel) {
                $base = LedgerEntry::query()->whereIn('area_id', $ids)->where('fund_classification', $fund)->where('channel', $channel);
                $opening = (int) (clone $base)->where('posted_on', '<', $start->toDateString())->sum('amount');
                $current = (clone $base)->whereBetween('posted_on', [$start->toDateString(), $end]);
                $in = (int) (clone $current)->where('amount', '>', 0)->sum('amount');
                $out = -(int) (clone $current)->where('amount', '<', 0)->sum('amount');
                $groups[] = ['fund_classification' => $fund, 'channel' => $channel, 'opening' => $opening, 'inflow' => $in, 'outflow' => $out, 'closing' => $opening + $in - $out];
            }
        }
        $invoiced = $paid = 0;
        foreach (Invoice::query()->whereIn('area_id', $ids)->where('period', $start->toDateString())->where('state', 'issued')->cursor() as $invoice) {
            $invoiced += $invoice->amount;
            $paid += $this->paid($invoice, $end);
        }

        return ['area_id' => $area->public_id, 'period' => $period, 'currency' => 'IDR', 'funds' => $groups, 'invoice_total' => $invoiced, 'invoice_paid_at_month_end' => $paid, 'invoice_outstanding_at_month_end' => $invoiced - $paid, 'closing_balance' => array_sum(array_column($groups,'closing'))];
    }
}
