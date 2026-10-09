<?php

namespace Modules\Billing\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\AccountingPeriod;
use Modules\Billing\Models\BankAccount;
use Modules\Billing\Models\Expense;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\LedgerEntry;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\BillingScope;
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;

class BillingController
{
    private const LISTS = ['payment-types' => [PaymentType::class, 'invoices.view', false], 'invoices' => [Invoice::class, 'invoices.view', true], 'bank-accounts' => [BankAccount::class, 'payments.manual.view', false], 'submissions' => [PaymentSubmission::class, 'payments.manual.view', true], 'receipts' => [Receipt::class, 'receipts.view', true], 'expenses' => [Expense::class, 'ledger.view', false], 'ledger' => [LedgerEntry::class, 'ledger.view', false], 'periods' => [AccountingPeriod::class, 'reports.view', false]];

    public function __construct(private BillingService $billing, private BillingScope $scope) {}

    public function index(Request $request, string $collection): JsonResponse
    {
        [$class,$permission,$households] = self::LISTS[$collection];
        $request->validate(['area_id' => ['sometimes', 'uuid'], 'household_id' => ['sometimes', 'uuid'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'period' => ['sometimes', 'date_format:Y-m']]);
        $query = $this->scope->filter($class::query(), $request->user(), $permission, $households);
        if (in_array($collection, ['bank-accounts', 'payment-types'], true)) {
            $ids = Household::query()->whereIn('id', app(ScopeResolver::class)->memberHouseholdIds($request->user()))->pluck('area_id');
            $managed = app(ScopeResolver::class)->constrain(Area::query(), $request->user(), $permission)->pluck('id');
            $parents = Area::query()->whereIn('id', [...$ids, ...$managed])->whereNotNull('parent_id')->pluck('parent_id');
            $query->orWhereIn('area_id', [...$ids, ...$parents]);
            $query = $class::query()->whereIn('id', $query->select('id'));
        }
        if ($request->filled('area_id')) {
            $query->where('area_id', Area::query()->where('public_id', $request->input('area_id'))->value('id'));
        }
        if ($households && $request->filled('household_id')) {
            $query->where('household_id', Household::query()->where('public_id', $request->input('household_id'))->value('id'));
        }
        if ($request->filled('period') && in_array($collection, ['invoices', 'periods'], true)) {
            $query->where('period', $request->input('period').'-01');
        }
        if ($request->filled('period') && in_array($collection, ['ledger', 'receipts'], true)) {
            $date = Carbon::createFromFormat('!Y-m', $request->input('period'));
            $query->whereBetween($collection === 'ledger' ? 'posted_on' : 'paid_on', [$date->toDateString(), $date->endOfMonth()->toDateString()]);
        }

        return ApiResponse::success($query->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($model) => (new BillingResource($model))->resolve($request)));
    }

    public function show(Request $request, string $collection, string $id): JsonResponse
    {
        [$class,$permission,$households] = self::LISTS[$collection];
        $model = $class::query()->where('public_id', $id)->firstOrFail();
        if ($households) {
            $this->scope->household($request->user(), $permission, Household::query()->findOrFail($model->household_id));
        } else {
            $this->scope->area($request->user(), $permission, Area::query()->findOrFail($model->area_id));
        }

        return ApiResponse::success(new BillingResource($model));
    }

    public function paymentType(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:100'], 'collection_policy' => ['required', 'in:must_settle_in_period,can_accumulate'], 'fund_classification' => ['required', 'in:operational,pass_through']]);

        return ApiResponse::success($this->billing->paymentType($request->user(), $this->key($request), $data), 201);
    }

    public function tariffs(Request $request, PaymentType $type): JsonResponse
    {
        $this->scope->area($request->user(), 'billing.manage', Area::query()->findOrFail($type->area_id));
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::success(Tariff::query()->where('payment_type_id', $type->id)->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($tariff) => (new BillingResource($tariff))->resolve($request)));
    }

    public function tariff(Request $request, PaymentType $type): JsonResponse
    {
        $data = $request->validate(['amount' => $this->amount(), 'starts_at' => ['required', 'date_format:Y-m-d'], 'ends_at' => ['nullable', 'date_format:Y-m-d', 'after:starts_at']]);

        return ApiResponse::success($this->billing->tariff($request->user(), $this->key($request), $type, $data), 201);
    }

    public function endTariff(Request $request, Tariff $tariff): JsonResponse
    {
        return ApiResponse::success($this->billing->endTariff($request->user(), $this->key($request), $tariff, $request->validate(['ends_at' => ['required', 'date_format:Y-m-d']])));
    }

    public function bankAccount(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'bank_name' => ['required', 'string', 'max:100'], 'account_number' => ['required', 'string', 'max:80'], 'account_holder' => ['required', 'string', 'max:100']]);

        return ApiResponse::success($this->billing->bankAccount($request->user(), $this->key($request), $data), 201);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'payment_type_id' => ['required', 'uuid'], 'household_ids' => ['sometimes', 'array', 'min:1', 'max:500'], 'household_ids.*' => ['required', 'uuid', 'distinct'], 'period' => ['required', 'date_format:Y-m'], 'subject' => ['sometimes', 'string', 'max:100'], 'due_date' => ['required', 'date_format:Y-m-d'], 'settle_by' => ['sometimes', 'date_format:Y-m-d'], 'state' => ['sometimes', 'in:draft,issued']]);

        return ApiResponse::success($this->billing->generate($request->user(), $this->key($request), $data), 201);
    }

    public function invoiceAction(Request $request, Invoice $invoice, string $action): JsonResponse
    {
        return ApiResponse::success($this->billing->invoiceAction($request->user(), $this->key($request), $invoice, $action));
    }

    public function cash(Request $request): JsonResponse
    {
        $data = $request->validate(['household_id' => ['required', 'uuid'], 'amount' => $this->amount(), 'paid_on' => $this->date(), ...$this->allocationRules()]);

        return ApiResponse::success($this->billing->cash($request->user(), $this->key($request), $data), 201);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate(['household_id' => ['required', 'uuid'], 'amount' => $this->amount(), 'transferred_at' => $this->date(), 'destination_account_id' => ['required', 'uuid'], 'proof_file_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:2000'], ...$this->allocationRules()]);

        return ApiResponse::success($this->billing->submit($request->user(), $this->key($request), $data), 201);
    }

    public function review(Request $request, PaymentSubmission $submission, string $action): JsonResponse
    {
        $data = $request->validate(['review_note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:2000'], 'paid_on' => ['sometimes', ...array_slice($this->date(), 1)], 'amount' => ['prohibited'], 'allocations' => ['prohibited']]);

        return ApiResponse::success($this->billing->review($request->user(), $this->key($request), $submission, $action, $data));
    }

    public function reverseReceipt(Request $request, Receipt $receipt): JsonResponse
    {
        return ApiResponse::success($this->billing->reverseReceipt($request->user(), $this->key($request), $receipt, $request->validate($this->reversalRules())));
    }

    public function expense(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'amount' => $this->amount(), 'description' => ['required', 'string', 'max:2000'], 'fund_classification' => ['required', 'in:operational,pass_through'], 'channel' => ['required', 'in:cash,bank'], 'proof_file_id' => ['nullable', 'uuid']]);

        return ApiResponse::success($this->billing->expense($request->user(), $this->key($request), $data), 201);
    }

    public function expenseAction(Request $request, Expense $expense, string $action): JsonResponse
    {
        $data = $action === 'post' ? $request->validate(['posted_on' => $this->date()]) : [];

        return ApiResponse::success($this->billing->expenseAction($request->user(), $this->key($request), $expense, $action, $data));
    }

    public function journal(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'kind' => ['required', 'in:transfer,adjustment'], 'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000000000,1000000000000'], 'posted_on' => $this->date(), 'fund_classification' => ['required', 'in:operational,pass_through'], 'channel' => ['required', 'in:cash,bank'], 'destination_channel' => ['required_if:kind,transfer', 'in:cash,bank'], 'reason' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success($this->billing->journal($request->user(), $this->key($request), $data), 201);
    }

    public function reverseJournal(Request $request, LedgerEntry $entry): JsonResponse
    {
        return ApiResponse::success($this->billing->reverseJournal($request->user(), $this->key($request), $entry, $request->validate($this->reversalRules())));
    }

    public function close(Request $request): JsonResponse
    {
        return ApiResponse::success($this->billing->close($request->user(), $this->key($request), $request->validate(['area_id' => ['required', 'uuid'], 'period' => ['required', 'date_format:Y-m']])));
    }

    public function report(Request $request, BillingReport $report): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'period' => ['required', 'date_format:Y-m']]);
        $area = Area::query()->where('public_id', $data['area_id'])->firstOrFail();
        $this->scope->area($request->user(), 'reports.view', $area);

        return DB::connection('rukun')->transaction(function () use ($area, $data, $report): JsonResponse {
            Area::query()->whereKey($area->parent_id ?? $area->id)->sharedLock()->firstOrFail();
            $closed = AccountingPeriod::query()->where('area_id', $area->id)->where('period', $data['period'].'-01')->first();

            return ApiResponse::success(['closed' => $closed !== null, 'report' => $closed?->report ?? $report->monthly($area, $data['period'])]);
        });
    }

    private function key(Request $request): string
    {
        return validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }

    private function amount(): array
    {
        return ['required', 'integer', 'min:1', 'max:1000000000000'];
    }

    private function date(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
    }

    private function allocationRules(): array
    {
        return ['allocations' => ['sometimes', 'array', 'min:1', 'max:100'], 'allocations.*' => ['required', 'array:invoice_id,amount'], 'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'], 'allocations.*.amount' => $this->amount()];
    }

    private function reversalRules(): array
    {
        return ['posted_on' => $this->date(), 'reason' => ['required', 'string', 'max:2000']];
    }
}
