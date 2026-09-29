<?php

namespace Modules\Wifi\Services;

use Illuminate\Support\Facades\DB;
use Modules\Wifi\Models\GallonBenefit;

class WifiFinancialGuard
{
    public static function receipt(int $receiptId): void
    {
        $used = DB::connection('rukun')->table('wifi_finance_receipts')->where('receipt_id', $receiptId)->whereIn('finance_id', app(SettlementService::class)->active()->select('id'))->exists();
        $benefits = GallonBenefit::query()->whereIn('bill_id', DB::connection('rukun')->table('wifi_bills')->whereIn('invoice_id', DB::connection('rukun')->table('receipt_allocations')->where('receipt_id', $receiptId)->select('invoice_id'))->select('id'))->where(fn ($q) => $q->where('status', 'active')->orWhere('confirmed', '>', 0))->exists();
        abort_if($used || $benefits, 409, __('wifi::messages.protected_receipt'));
    }

    public static function ledger(int $entryId): void
    {
        abort_if(DB::connection('rukun')->table('wifi_finance_ledger')->where('ledger_id', $entryId)->exists(), 409, __('wifi::messages.protected_ledger'));
    }
}
