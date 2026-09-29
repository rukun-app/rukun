<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingReport;
use Modules\Community\Models\Area;

class InvoiceDueSoonCommand extends Command
{
    protected $signature = 'billing:notify-due';

    protected $description = 'Persist due-soon invoice events once per invoice per day';

    public function handle(BillingReport $report): int
    {
        $count = 0;
        foreach (Invoice::query()->where('state', 'issued')->whereBetween('due_date', [today()->toDateString(), today()->addDays(3)->toDateString()])->lazyById(100) as $invoice) {
            DB::connection('rukun')->transaction(function () use ($invoice, $report, &$count): void {
                $area = Area::query()->findOrFail($invoice->area_id);
                Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
                $invoice->refresh();
                if ($invoice->state !== 'issued' || $report->paid($invoice) >= $invoice->amount) {
                    return;
                }
                $inserted = DB::connection('rukun')->table('billing_notices')->insertOrIgnore(['invoice_id' => $invoice->id, 'day' => today()->toDateString()]);
                if ($inserted) {
                    BillingEvents::emit('invoice.due_soon', $invoice, $invoice->household_id);
                    $count++;
                }
            });
        }
        $this->info("Published due-soon events for {$count} invoice(s).");

        return self::SUCCESS;
    }
}
