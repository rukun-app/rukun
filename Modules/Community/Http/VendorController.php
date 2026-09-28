<?php

namespace Modules\Community\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;

class VendorController
{
    public function index(Request $request, ScopeResolver $scopes): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Vendor::query();
        if (! $scopes->global($request->user(), 'vendors.view')) {
            $query->whereIn('id', $scopes->assignments($request->user(), 'vendors.view')->whereNotNull('vendor_id')->pluck('vendor_id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, ScopeResolver $scopes): JsonResponse
    {
        abort_unless($scopes->global($request->user(), 'vendors.manage'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $vendor = DB::connection('rukun')->transaction(function () use ($data) {
            $vendor = Vendor::query()->create($data);
            CommunityAudit::record('vendor.created', $vendor);

            return $vendor;
        });

        return ApiResponse::success($vendor->refresh(), 201);
    }

    public function update(Request $request, Vendor $vendor, ScopeResolver $scopes): JsonResponse
    {
        abort_unless($scopes->allowsVendor($request->user(), 'vendors.manage', $vendor), 403);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'status' => ['sometimes', 'in:active,inactive']]);
        DB::connection('rukun')->transaction(function () use ($vendor, $data): void {
            $vendor->update($data);
            CommunityAudit::record('vendor.updated', $vendor);
        });

        return ApiResponse::success($vendor);
    }
}
