<?php

namespace Modules\Community\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Area;
use Modules\Community\Models\RoleAssignment;
use Spatie\Permission\Models\Role;

class ScopeResolver
{
    public function assignments(User $user, string $permission): Builder
    {
        // Role is Core-owned, so resolve its IDs using the Core connection.
        $roles = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->pluck('id');

        return RoleAssignment::query()->where('user_id', $user->id)->whereIn('role_id', $roles)
            ->where('status', 'active')->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function global(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission) || $this->assignments($user, $permission)->where('scope_type', 'global')->exists();
    }

    public function allows(User $user, string $permission, ?Area $area): bool
    {
        if ($this->global($user, $permission)) {
            return true;
        }
        if (! $area) {
            return false;
        }

        return $this->assignments($user, $permission)->whereIn('area_id', array_filter([$area->id, $area->parent_id]))->exists();
    }

    public function constrain(Builder $query, User $user, string $permission): Builder
    {
        if ($this->global($user, $permission)) {
            return $query;
        }
        $ids = $this->assignments($user, $permission)->whereNotNull('area_id')->pluck('area_id');

        return $query->where(fn ($query) => $query->whereIn('id', $ids)->orWhereIn('parent_id', $ids));
    }

    public function accountArea(User $user): ?Area
    {
        $id = DB::connection('rukun')->table('account_scopes')->where('user_id', $user->id)->value('area_id');

        return $id ? Area::query()->find($id) : null;
    }

    public function recoverable(User $actor, User $target): bool
    {
        $area = $this->accountArea($target);

        // Recovery in F1 is for provisioned resident accounts, never privileged operators.
        return ! $actor->is($target) && $area && $this->allows($actor, 'users.recover-account', $area)
            && $target->getAllPermissions()->isEmpty()
            && ! RoleAssignment::query()->where('user_id', $target->id)->where('status', 'active')->exists();
    }
}
