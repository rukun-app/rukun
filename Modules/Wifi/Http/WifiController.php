<?php

namespace Modules\Wifi\Http;

use Core\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Community\Models\Area;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\ScopeResolver;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiPackage;
use Modules\Wifi\Services\WifiService;

class WifiController
{
    public function __construct(private WifiService $wifi, private ScopeResolver $scopes) {}

    public function index(Request $request, string $collection): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'vendor_id' => ['sometimes', 'uuid']]);
        $query = $this->visible($request, $collection);
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', Vendor::query()->where('public_id', $request->input('vendor_id'))->value('id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($model) => (new WifiResource($model))->resolve($request)));
    }

    public function show(Request $request, string $collection, string $id): JsonResponse
    {
        return ApiResponse::success(new WifiResource($this->visible($request, $collection)->where('public_id', $id)->firstOrFail()));
    }

    public function package(Request $request): JsonResponse
    {
        $data = $request->validate(['vendor_id' => ['required', 'uuid'], 'payment_type_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100'], 'due_day' => ['required', 'integer', 'min:1', 'max:28'], 'settle_day' => ['required', 'integer', 'gte:due_day', 'max:28'], 'allow_advance' => ['sometimes', 'boolean'], 'remit_day' => ['sometimes', 'integer', 'gte:settle_day', 'max:28'], 'gallon_quota' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'claim_days' => ['sometimes', 'integer', 'min:1', 'max:365']]);

        return ApiResponse::success($this->wifi->package($request->user(), $this->key($request), $data), 201);
    }

    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['package_id' => ['required', 'uuid'], 'household_id' => ['required', 'uuid'], 'starts_on' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::success($this->wifi->customer($request->user(), $this->key($request), $data), 201);
    }

    public function end(Request $request, WifiCustomer $customer): JsonResponse
    {
        $data = $request->validate(['ends_on' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::success($this->wifi->end($request->user(), $this->key($request), $customer, $data));
    }

    public function bill(Request $request, WifiCustomer $customer): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);

        return ApiResponse::success($this->wifi->bill($request->user(), $this->key($request), $customer, $data), 201);
    }

    public function visible(Request $request, string $collection): Builder
    {
        $query = ($collection === 'packages' ? WifiPackage::class : WifiCustomer::class)::query();
        $actor = $request->user();
        if ($this->scopes->global($actor, 'wifi.view')) {
            return $query;
        }
        $areas = $this->scopes->constrain(Area::query(), $actor, 'wifi.view')->pluck('id');
        $vendors = Vendor::query()->where('status', 'active')->whereIn('id', $this->scopes->assignments($actor, 'wifi.view')->whereNotNull('vendor_id')->select('vendor_id'))->pluck('id');
        $households = $actor->must_change_password ? [] : $this->scopes->memberHouseholdIds($actor);

        return $query->where(function ($q) use ($areas, $vendors, $households, $collection): void {
            $q->whereIn('area_id', $areas)->orWhereIn('vendor_id', $vendors);
            if ($collection === 'customers') {
                $q->orWhereIn('household_id', $households);
            } else {
                $q->orWhereIn('id', WifiCustomer::query()->whereIn('household_id', $households)->select('package_id'));
                $q->orWhereIn('area_id', Area::query()->whereIn('id', $areas)->whereNotNull('parent_id')->select('parent_id'));
            }
        });
    }

    private function key(Request $request): string
    {
        return validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }
}
