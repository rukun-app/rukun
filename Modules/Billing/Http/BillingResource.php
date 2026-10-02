<?php

namespace Modules\Billing\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\BankAccount;
use Modules\Billing\Models\Expense;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\ReceiptReversal;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\GatewayBilling;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Files\Models\StoredFile;
use Modules\Payments\Models\Payment;

class BillingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = $this->resource;
        $data = ['public_id' => $model->public_id];
        if ($model->area_id) {
            $data['area_id'] = Area::query()->findOrFail($model->area_id)->public_id;
        }
        if ($model->household_id) {
            $data['household_id'] = Household::query()->findOrFail($model->household_id)->public_id;
        }
        $fields = match (true) {
            $model instanceof PaymentType => ['code', 'name', 'collection_policy', 'fund_classification'],
            $model instanceof Tariff => ['amount', 'starts_at', 'ends_at'],
            $model instanceof Invoice => ['period', 'subject', 'amount', 'due_date', 'settle_by', 'collection_policy', 'fund_classification'],
            $model instanceof BankAccount => ['bank_name', 'account_number', 'account_holder'],
            $model instanceof PaymentSubmission => ['amount', 'transferred_at', 'note', 'status', 'reviewed_at', 'review_note', 'allocations'],
            $model instanceof Receipt => ['number', 'amount', 'paid_on', 'paid_at', 'channel'],
            $model instanceof Expense => ['amount', 'description', 'fund_classification', 'channel', 'status', 'approved_at', 'posted_on'],
            $model instanceof LedgerEntry => ['posted_on', 'kind', 'amount', 'fund_classification', 'channel', 'reason', 'transfer_group'],
            $model instanceof AccountingPeriod => ['period', 'report', 'closed_at'],
            default => [],
        };
        foreach ($fields as $field) {
            $value = $model->{$field};
            $data[$field] = $value instanceof \DateTimeInterface ? $value->format(in_array($field, ['reviewed_at', 'approved_at', 'closed_at', 'paid_at'], true) ? DATE_ATOM : 'Y-m-d') : $value;
        }
        foreach (['payment_type_id' => PaymentType::class, 'tariff_id' => Tariff::class, 'destination_account_id' => BankAccount::class, 'receipt_id' => Receipt::class, 'expense_id' => Expense::class, 'reverses_id' => LedgerEntry::class, 'submission_id' => PaymentSubmission::class, 'gateway_payment_id' => Payment::class] as $field => $class) {
            if ($model->{$field}) {
                $data[$field] = $class::query()->findOrFail($model->{$field})->public_id;
            }
        }
        foreach (['submitted_by', 'reviewed_by', 'created_by', 'approved_by', 'closed_by'] as $field) {
            if ($model->{$field}) {
                $data[$field] = User::query()->findOrFail($model->{$field})->public_id;
            }
        }
        if ($model->proof_file_id) {
            $data['proof_file_id'] = StoredFile::withTrashed()->findOrFail($model->proof_file_id)->public_id;
        }
        if ($model instanceof Invoice) {
            $data = [...$data, ...app(BillingReport::class)->invoice($model)];
            $data['reserved_amount'] = GatewayBilling::reserved($model);
            $data['payable_amount'] = $model->state === 'issued' ? max(0, $model->amount - app(BillingReport::class)->paid($model) - $data['reserved_amount']) : 0;
        }
        if ($model instanceof Expense) {
            $data['reversed'] = LedgerEntry::query()->where('expense_id', $model->id)->where('kind', 'reversal')->exists();
        }
        if ($model instanceof Receipt) {
            $data['reversal_id'] = ReceiptReversal::query()->where('receipt_id', $model->id)->value('public_id');
            $data['allocations'] = DB::connection('rukun')->table('receipt_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')->where('a.receipt_id', $model->id)->get(['i.public_id as invoice_id', 'a.amount'])->all();
        }

        return $data;
    }
}
