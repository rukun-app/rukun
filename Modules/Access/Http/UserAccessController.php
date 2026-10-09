<?php

namespace Modules\Access\Http;

use App\Enums\UserStatus;
use App\Models\User;
use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Community\Models\RoleAssignment;
use Spatie\Permission\Models\Role;

class UserAccessController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $request->integer('per_page', 20);

        $users = User::query()
            ->with('roles:id,name')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->trim()->toString().'%';
                $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search));
            })
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->string('role')->isNotEmpty(), fn ($query) => $query->role($request->string('role')->toString()))
            ->orderBy('name')
            ->orderBy('id')
            ->collectionPaginate($perPage)
            ->withQueryString();

        $users->getCollection()->transform(function (User $user): array {
            return $this->enrichUserPayload($user);
        });

        return ApiResponse::success($users);
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success([
            ...$this->enrichUserPayload($user),
            'active_tokens' => $user->tokens()->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'status' => $data['status'] ?? UserStatus::Active,
                'email_verified_at' => now(),
            ]);
            $user->assignRole($data['roles'] ?? ['user']);
            Audit::record('user.created', $user, ['roles' => $data['roles'] ?? ['user']]);

            return $user;
        });

        return ApiResponse::success($user->load('roles:id,name'), 201);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);
        abort_if($request->user()->is($user) && $data['status'] === UserStatus::Suspended->value, 422, __('api.access.self_suspend'));
        DB::transaction(function () use ($user, $data): void {
            User::query()->lockForUpdate()->get(['id']);
            $user->refresh();
            abort_if($data['status'] === UserStatus::Suspended->value && $this->isLastAccessManager($user), 422, __('api.access.last_manager_suspend'));
            $before = $user->status->value;
            $user->update(['status' => $data['status']]);
            if ($user->status === UserStatus::Suspended) {
                $user->tokens()->delete();
            }
            Audit::record('user.status_updated', $user, ['before' => $before, 'after' => $data['status']]);
        });

        return ApiResponse::success($user);
    }

    public function roles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array'], 'roles.*' => ['string', Rule::exists('roles', 'name')]]);
        $roleNames = $request->json('roles', []);
        abort_if($request->user()->is($user) && $roleNames === [], 422, __('api.access.self_roles'));
        DB::transaction(function () use ($user, $roleNames): void {
            User::query()->lockForUpdate()->get(['id']);
            $user->refresh();
            $before = $user->getRoleNames()->all();
            $roleIds = Role::query()->whereIn('name', $roleNames)->pluck('id')->all();
            $keepsAccessManagement = Role::query()->whereKey($roleIds)->whereHas('permissions', fn ($query) => $query->where('name', 'users.assign-roles'))->exists();
            abort_if(! $keepsAccessManagement && $this->isLastAccessManager($user), 422, __('api.access.last_manager_roles'));
            $user->roles()->sync($roleIds);
            $user->unsetRelation('roles');
            Audit::record('user.roles_updated', $user, ['before' => $before, 'after' => $roleNames]);
        });

        return ApiResponse::success(['roles' => $user->getRoleNames()->values()]);
    }

    private function enrichUserPayload(User $user): array
    {
        $user->loadMissing('roles:id,name');
        $accessRoles = $user->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all();
        $communityRoles = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get();

        $communityRoleNames = $communityRoles->isNotEmpty()
            ? Role::query()->whereIn('id', $communityRoles->pluck('role_id')->unique()->all())->get(['id', 'name'])->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all()
            : [];

        $roleNames = array_values(array_unique(array_merge($accessRoles, $communityRoleNames), SORT_REGULAR));

        $permissions = $user->getAllPermissions()->pluck('name')->all();
        if ($communityRoles->isNotEmpty()) {
            $communityPermissions = Role::query()->whereIn('id', $communityRoles->pluck('role_id')->unique()->all())->with('permissions:id,name')->get()->flatMap(fn ($role) => $role->permissions->pluck('name')->all())->all();
            $permissions = array_values(array_unique(array_merge($permissions, $communityPermissions)));
        }

        $payload = $user->toArray();
        $payload['roles'] = $roleNames;
        $payload['permissions'] = array_values(array_unique(array_filter($permissions, fn ($permission) => is_string($permission))));
        sort($payload['permissions']);

        return $payload;
    }

    private function isLastAccessManager(User $user): bool
    {
        return $user->can('users.assign-roles')
            && ! User::permission('users.assign-roles')->whereKeyNot($user->getKey())->exists();
    }
}
