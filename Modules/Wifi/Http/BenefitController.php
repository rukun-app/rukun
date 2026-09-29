<?php

namespace Modules\Wifi\Http;

use Core\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Area;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\ScopeResolver;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Models\GallonClaim;
use Modules\Wifi\Models\GallonEntry;
use Modules\Wifi\Models\WifiBill;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiFinance;
use Modules\Wifi\Services\GallonService;
use Modules\Wifi\Services\SettlementService;

class BenefitController
{
    public function __construct(private GallonService $gallons, private SettlementService $settlement) {}

    public function index(Request $request, string $resource): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'bill_id' => ['sometimes', 'uuid'], 'benefit_id' => ['sometimes', 'uuid']]);
        $query = $this->visible($request, $resource);
        if ($request->filled('bill_id')) {
            $id = WifiBill::query()->where('public_id', $request->input('bill_id'))->value('id');
            if ($resource === 'claims' || $resource === 'gallon-ledger') {
                $query->whereIn('benefit_id', GallonBenefit::query()->where('bill_id', $id)->select('id'));
            } else {
                $query->where($resource === 'bills' ? 'id' : 'bill_id', $id);
            }
        }
        if ($request->filled('benefit_id') && in_array($resource, ['claims', 'gallon-ledger'], true)) {
            $query->where('benefit_id', GallonBenefit::query()->where('public_id', $request->input('benefit_id'))->value('id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($model) => $this->serialize($model)));
    }

    public function show(Request $request, string $resource, string $id): JsonResponse
    {
        return ApiResponse::success($this->serialize($this->visible($request, $resource)->where('public_id', $id)->firstOrFail()));
    }

    public function settlement(Request $request, WifiBill $bill): JsonResponse
    {
        [, , $area] = $this->settlement->context($bill);
        abort_unless(app(ScopeResolver::class)->allows($request->user(), 'wifi.settle', $area), 403);

        return ApiResponse::success($this->settlement->summary($bill));
    }

    public function finance(Request $request, WifiBill $bill, string $action): JsonResponse
    {
        $data = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'channel' => ['required', 'in:cash,bank'], 'reference' => ['required', 'string', 'max:150'], 'amount' => [$action === 'recover' ? 'required' : 'prohibited', 'integer', 'min:1', 'max:1000000000000']]);

        return ApiResponse::success($this->settlement->write($request->user(), $this->key($request), $bill, $action, $data), 201);
    }

    public function reverseFinance(Request $request, WifiFinance $finance): JsonResponse
    {
        $data = $request->validate(['posted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['required', 'string', 'max:150']]);

        return ApiResponse::success($this->settlement->write($request->user(), $this->key($request), WifiBill::query()->findOrFail($finance->bill_id), 'reverse', $data, $finance));
    }

    public function grant(Request $request, WifiBill $bill): JsonResponse
    {
        return ApiResponse::success($this->gallons->grant($request->user(), $this->key($request), $bill), 201);
    }

    public function reserve(Request $request, GallonBenefit $benefit): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000'], 'reference' => ['required', 'string', 'max:100']]);

        return ApiResponse::success($this->gallons->reserve($request->user(), $this->key($request), $benefit, $data), 201);
    }

    public function claim(Request $request, GallonClaim $claim, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => [$action === 'reverse' ? 'required' : 'sometimes', 'string', 'max:2000']]);

        return ApiResponse::success($this->gallons->claim($request->user(), $this->key($request), $claim, $action, $data));
    }

    public function reverseGrant(Request $request, GallonBenefit $benefit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success($this->gallons->reverseGrant($request->user(), $this->key($request), $benefit, $data));
    }

    private function visible(Request $request, string $resource): Builder
    {
        $customers = app(WifiController::class)->visible($request, 'customers')->select('id');
        if ($resource === 'finance') {
            $areas = app(ScopeResolver::class)->constrain(Area::query(), $request->user(), 'wifi.settle')->select('id');
            $customers = WifiCustomer::query()->whereIn('area_id', $areas)->select('id');
        }
        $bills = WifiBill::query()->whereIn('customer_id', $customers);
        $benefits = GallonBenefit::query()->whereIn('bill_id', (clone $bills)->select('id'));

        return match ($resource) {
            'bills' => $bills,
            'benefits' => $benefits,
            'finance' => WifiFinance::query()->whereIn('bill_id', $bills->select('id')),
            'claims' => GallonClaim::query()->whereIn('benefit_id', $benefits->select('id')),
            'gallon-ledger' => GallonEntry::query()->whereIn('benefit_id', $benefits->select('id')),
        };
    }

    private function serialize(Model $model): array
    {
        $base = ['public_id' => $model->public_id];
        if ($model instanceof WifiBill) {
            return [...$base, 'customer_id' => WifiCustomer::query()->findOrFail($model->customer_id)->public_id, 'period' => $model->period->format('Y-m'), 'eligible' => $this->settlement->eligible($model)];
        }
        if ($model instanceof GallonBenefit) {
            return [...$base, 'bill_id' => WifiBill::query()->findOrFail($model->bill_id)->public_id, 'quota' => $model->quota, 'available' => $model->available, 'reserved' => $model->reserved, 'confirmed' => $model->confirmed, 'expires_on' => $model->expires_on->toDateString(), 'status' => $model->status, 'claimable' => $model->status === 'active' && today()->lessThanOrEqualTo($model->expires_on) && $this->settlement->eligible(WifiBill::query()->findOrFail($model->bill_id))];
        }
        if ($model instanceof GallonClaim) {
            return [...$base, 'benefit_id' => GallonBenefit::query()->findOrFail($model->benefit_id)->public_id, 'vendor_id' => Vendor::query()->findOrFail($model->vendor_id)->public_id, 'reference' => $model->reference, 'quantity' => $model->quantity, 'status' => $model->status];
        }
        if ($model instanceof GallonEntry) {
            return [...$base, 'benefit_id' => GallonBenefit::query()->findOrFail($model->benefit_id)->public_id, 'claim_id' => $model->claim_id ? GallonClaim::query()->findOrFail($model->claim_id)->public_id : null, 'event' => $model->event, 'available_delta' => $model->available_delta, 'reserved_delta' => $model->reserved_delta, 'confirmed_delta' => $model->confirmed_delta, 'created_at' => $model->created_at->toISOString()];
        }

        return [...$base, 'bill_id' => WifiBill::query()->findOrFail($model->bill_id)->public_id, 'kind' => $model->kind, 'amount' => $model->amount, 'advance' => $model->advance, 'channel' => $model->channel, 'reference' => $model->reference, 'posted_on' => $model->posted_on->toDateString(), 'parent_id' => $model->parent_id ? WifiFinance::query()->findOrFail($model->parent_id)->public_id : null, 'reverses_id' => $model->reverses_id ? WifiFinance::query()->findOrFail($model->reverses_id)->public_id : null, 'reversed_by' => WifiFinance::query()->where('reverses_id', $model->id)->value('public_id'), 'source_receipts' => DB::connection('rukun')->table('wifi_finance_receipts as s')->join('receipts as r', 'r.id', '=', 's.receipt_id')->where('s.finance_id', $model->id)->orderBy('r.id')->get(['r.public_id', 's.amount'])->map(fn ($source) => ['receipt_id' => $source->public_id, 'amount' => (int) $source->amount])->all(), 'ledger_ids' => DB::connection('rukun')->table('wifi_finance_ledger as f')->join('ledger_entries as l', 'l.id', '=', 'f.ledger_id')->where('f.finance_id', $model->id)->orderBy('l.id')->pluck('l.public_id')->all()];
    }

    private function key(Request $request): string
    {
        return validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }
}
