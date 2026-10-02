<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;

class BillingScope
{
    public function __construct(private ScopeResolver $scopes) {}

    public function area(User $actor, string $permission, Area $area): void
    {
        abort_unless($this->scopes->allows($actor, $permission, $area), 403);
    }

    public function household(User $actor, string $permission, Household $household): void
    {
        $member = in_array($permission, ['invoices.view', 'receipts.view', 'payments.manual.submit', 'payments.manual.view', 'payments.gateway.create'], true) && ! $actor->must_change_password && in_array($household->id, $this->scopes->memberHouseholdIds($actor), true);
        abort_unless($member || $this->scopes->allowsHousehold($actor, $permission, $household), 403);
    }

    public function filter(Builder $query, User $actor, string $permission, bool $households = false): Builder
    {
        if ($this->scopes->global($actor, $permission)) {
            return $query;
        }
        $areas = $this->scopes->constrain(Area::query(), $actor, $permission)->pluck('id');

        return $query->where(function ($query) use ($areas, $actor, $permission, $households): void {
            $query->whereIn('area_id', $areas);
            if ($households) {
                $ids = $this->scopes->households(Household::query(), $actor, $permission)->pluck('id')->all();
                if (! $actor->must_change_password && in_array($permission, ['invoices.view', 'receipts.view', 'payments.manual.view'], true)) {
                    $ids = array_merge($ids, $this->scopes->memberHouseholdIds($actor));
                }
                $query->orWhereIn('household_id', $ids);
            }
        });
    }
}
