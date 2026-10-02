<?php

namespace Modules\Access\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions:id,name')->orderBy('name')->get()->map(function (Role $role): array {
            $payload = $role->toArray();
            $payload['permissions'] = $role->permissions()->pluck('name')->sort()->values()->all();

            return $payload;
        });

        return ApiResponse::success($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'name')], 'permissions' => ['array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')]]);
        $data['permissions'] = $request->input('permissions', []);
        $role = DB::transaction(function () use ($data): Role {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);
            Audit::record('rbac.role_created', $role, ['permissions' => $data['permissions'] ?? []]);

            return $role;
        });

        $role->refresh();
        $payload = $role->toArray();
        $payload['permissions'] = $role->permissions()->pluck('name')->sort()->values()->all();

        return ApiResponse::success($payload, 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate(['updated_at' => ['required', 'date'], 'name' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('roles', 'name')->ignore($role)], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['string', Rule::exists('permissions', 'name')]]);
        if ($request->has('permissions')) {
            $data['permissions'] = $request->input('permissions');
        }
        DB::transaction(function () use ($role, $data): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            abort_unless($locked->updated_at?->equalTo($data['updated_at']), 409, __('api.access.role_conflict'));
            $before = ['name' => $locked->name, 'permissions' => $locked->permissions()->pluck('name')->all()];
            $locked->update(collect($data)->only('name')->all());
            if (array_key_exists('permissions', $data)) {
                $locked->syncPermissions($data['permissions']);
                $locked->touch();
            }
            Audit::record('rbac.role_updated', $locked, ['before' => $before, 'after' => $data]);
        });

        $role = $role->fresh();
        $payload = $role->toArray();
        $payload['permissions'] = $role->permissions()->pluck('name')->sort()->values()->all();

        return ApiResponse::success($payload);
    }

    public function destroy(Role $role): JsonResponse
    {
        DB::transaction(function () use ($role): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            abort_if($locked->users()->exists(), 422, __('api.access.role_in_use'));
            Audit::record('rbac.role_deleted', $locked, ['name' => $locked->name]);
            $locked->delete();
        });

        return ApiResponse::success(['message' => __('api.access.role_deleted')]);
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(Permission::query()->orderBy('name')->get(['id', 'name']));
    }
}
