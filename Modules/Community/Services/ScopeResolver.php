<?php

namespace Modules\Community\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Spatie\Permission\Models\Role;

class ScopeResolver
{
    public function assignments(User $user, string $permission): Builder
    {
        // Role is Core-owned, so resolve its IDs using the Core connection.
        $roles = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->pluck('id');

        return RoleAssignment::query()->when($user->status !== UserStatus::Active || $user->must_change_password, fn ($query) => $query->whereRaw('1=0'))->where('user_id', $user->id)->whereIn('role_id', $roles)
            ->where('status', 'active')->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function global(User $user, string $permission): bool
    {
        return ($user->status === UserStatus::Active && ! $user->must_change_password && $user->hasPermissionTo($permission)) || $this->assignments($user, $permission)->where('scope_type', 'global')->exists();
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

    public function memberHouseholdIds(User $user): array
    {
        if ($user->status !== UserStatus::Active) {
            return [];
        }

        return DB::connection('rukun')->table('residents as r')
            ->join('households as h', 'h.id', '=', 'r.household_id')
            ->join('household_memberships as m', fn ($join) => $join->on('m.resident_id', '=', 'r.id')->on('m.household_id', '=', 'h.id'))
            ->where('r.user_id', $user->id)->where('r.status', 'active')->where('h.status', 'active')->whereNull('m.ends_at')
            ->pluck('h.id')->all();
    }

    public function allowsHousehold(User $user, string $permission, Household $household): bool
    {
        if ($this->allows($user, $permission, Area::query()->findOrFail($household->area_id))) {
            return true;
        }
        if ($this->assignments($user, $permission)->where('household_id', $household->id)->exists()) {
            return true;
        }

        return ! $user->must_change_password && in_array($permission, ['households.view', 'residents.view'], true) && in_array($household->id, $this->memberHouseholdIds($user), true);
    }

    public function households(Builder $query, User $user, string $permission = 'households.view'): Builder
    {
        if ($this->global($user, $permission)) {
            return $query;
        }
        $areas = $this->constrain(Area::query(), $user, $permission)->pluck('id');
        $households = $this->assignments($user, $permission)->whereNotNull('household_id')->pluck('household_id')->all();
        if (! $user->must_change_password && in_array($permission, ['households.view', 'residents.view'], true)) {
            $households = array_merge($households, $this->memberHouseholdIds($user));
        }

        return $query->where(fn ($query) => $query->whereIn('area_id', $areas)->orWhereIn('id', $households));
    }

    public function allowsResident(User $user, string $permission, Resident $resident): bool
    {
        if ($this->allows($user, $permission, Area::query()->findOrFail($resident->area_id))) {
            return true;
        }
        $household = $resident->household_id ? Household::query()->find($resident->household_id) : null;

        return $household && $this->allowsHousehold($user, $permission, $household);
    }

    public function residents(Builder $query, User $user, string $permission = 'residents.view'): Builder
    {
        if ($this->global($user, $permission)) {
            return $query;
        }
        $areas = $this->constrain(Area::query(), $user, $permission)->pluck('id');
        $households = $this->households(Household::query(), $user, $permission)->pluck('id');

        return $query->where(fn ($query) => $query->whereIn('area_id', $areas)->orWhereIn('household_id', $households));
    }

    public function allowsVendor(User $user, string $permission, Vendor $vendor): bool
    {
        return $this->global($user, $permission) || $this->assignments($user, $permission)->where('vendor_id', $vendor->id)->exists();
    }

    public function unprivileged(User $user): bool
    {
        if ($user->getAllPermissions()->isNotEmpty()) {
            return false;
        }
        $privilegedRoles = Role::query()->whereHas('permissions', fn ($query) => $query->whereNotIn('name', ['households.view', 'residents.view']))->pluck('id');

        return ! RoleAssignment::query()->where('user_id', $user->id)->where('status', 'active')->whereIn('role_id', $privilegedRoles)->exists();
    }

    public function recoveryMethod(User $actor, User $target): ?string
    {
        if ($actor->is($target) || ! $this->unprivileged($target)) {
            return null;
        }
        $area = $this->accountArea($target);
        if ($area && $this->allows($actor, 'users.recover-account', $area)) {
            return 'administrative';
        }
        $shared = array_intersect($this->memberHouseholdIds($actor), $this->memberHouseholdIds($target));
        foreach ($shared as $id) {
            // Family relationship never grants this capability. Both accounts must still be active members.
            if ($this->assignments($actor, 'households.manage-accounts')->where('household_id', $id)->exists()) {
                return 'household';
            }
        }

        return null;
    }

    public function recoverable(User $actor, User $target): bool
    {
        return $this->recoveryMethod($actor, $target) !== null;
    }
}
