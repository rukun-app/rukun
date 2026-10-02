<?php

namespace Modules\Community\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Spatie\Permission\Models\Role;

class UserContexts
{
    public function forUser(User $user): array
    {
        if ($user->status !== UserStatus::Active || $user->must_change_password) {
            return [];
        }
        $contexts = [];
        $assignments = RoleAssignment::query()->where('user_id', $user->id)->where('status', 'active')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('id')->get();
        foreach ($assignments as $assignment) {
            $permissions = Role::query()->findOrFail($assignment->role_id)->permissions()->pluck('name')->all();
            $subject = match ($assignment->scope_type) {
                'rt', 'rw' => Area::query()->find($assignment->area_id),
                'household' => Household::query()->where('status', 'active')->find($assignment->household_id),
                'vendor' => Vendor::query()->where('status', 'active')->find($assignment->vendor_id),
                default => null,
            };
            if (! $subject && $assignment->scope_type !== 'global') {
                continue;
            }
            $this->add($contexts, $assignment->scope_type, $subject?->public_id ?? '', $subject?->name ?? $subject?->reference ?? 'Pengelolaan lingkungan', $permissions);
        }
        foreach (Household::query()->whereIn('id', app(ScopeResolver::class)->memberHouseholdIds($user))->orderBy('id')->get() as $household) {
            $this->add($contexts, 'household', $household->public_id, $household->reference, ['households.view', 'residents.view', 'invoices.view', 'receipts.view', 'payments.manual.submit', 'payments.manual.view', 'payments.gateway.create', 'wifi.view']);
        }

        return array_values($contexts);
    }

    private function add(array &$contexts, string $scope, string $id, string $label, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }
        $key = $scope.':'.$id;
        $permissions = array_values(array_unique([...($contexts[$key]['capabilities'] ?? []), ...$permissions]));
        sort($permissions);
        $contexts[$key] = ['id' => $key, 'type' => match ($scope) {
            'household' => 'household', 'vendor' => 'vendor', default => 'management',
        }, 'label' => $label, 'scope' => ['type' => $scope, 'id' => $id], 'capabilities' => $permissions];
    }
}
