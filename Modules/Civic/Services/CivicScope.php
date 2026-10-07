<?php

namespace Modules\Civic\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Civic\Models\Announcement;
use Modules\Civic\Models\CivicCase;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;

class CivicScope
{
    public function __construct(private ScopeResolver $scopes) {}

    public function active(User $user): bool
    {
        return $user->status === UserStatus::Active && ! $user->must_change_password;
    }

    public function manages(User $user, Area $area, string $permission): bool
    {
        return $this->scopes->allows($user, $permission, $area);
    }

    public function announcements(User $user): Builder
    {
        $areas = $this->scopes->constrain(Area::query(), $user, 'announcements.view')->get()->flatMap(fn ($area) => [$area->id, $area->parent_id])->filter()->unique()->all();
        $homes = Household::query()->whereIn('id', $this->scopes->memberHouseholdIds($user))->pluck('area_id');
        $memberAreas = Area::query()->whereIn('id', $homes)->get()->flatMap(fn ($area) => [$area->id, $area->parent_id])->filter()->unique()->all();
        $managed = $this->scopes->constrain(Area::query(), $user, 'announcements.manage')->pluck('id')->all();

        return Announcement::query()->when(! $this->active($user), fn ($q) => $q->whereRaw('1=0'))->where(function ($q) use ($areas, $memberAreas, $managed): void {
            $q->whereIn('area_id', $managed)->orWhere(fn ($q) => $q->where('status', 'published')->whereIn('area_id', array_unique([...$areas, ...$memberAreas])));
        });
    }

    public function announcement(User $user, Announcement $announcement): bool
    {
        return $this->announcements($user)->whereKey($announcement->id)->exists();
    }

    public function cases(User $user, string $kind): Builder
    {
        $areas = $this->scopes->constrain(Area::query(), $user, $kind === 'report' ? 'reports.manage' : 'letters.manage')->pluck('id');

        return CivicCase::query()->where('kind', $kind)->when(! $this->active($user), fn ($q) => $q->whereRaw('1=0'))->where(fn ($q) => $q->where('reporter_id', $user->id)->orWhereIn('area_id', $areas));
    }

    public function case(User $user, CivicCase $case): bool
    {
        return $this->cases($user, $case->kind)->whereKey($case->id)->exists();
    }
}
