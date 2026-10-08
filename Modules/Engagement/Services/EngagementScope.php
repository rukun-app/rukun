<?php

namespace Modules\Engagement\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Civic\Services\CivicScope;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Incident;
use Modules\Engagement\Models\Participant;

class EngagementScope
{
    public function __construct(private ScopeResolver $scopes, private CivicScope $civic) {}

    public function active(User $user): bool
    {
        return $this->civic->active($user);
    }

    public function manages(User $user, CommunityEvent $event): bool
    {
        return $this->scopes->allows($user, $event->kind === 'patrol' ? 'patrol.manage' : 'activities.manage', Area::query()->findOrFail($event->area_id));
    }

    public function member(User $user, Household $home, Area $area): bool
    {
        return $this->active($user) && in_array($home->id, $this->scopes->memberHouseholdIds($user), true) && ($home->area_id === $area->id || Area::query()->whereKey($home->area_id)->where('parent_id', $area->id)->exists());
    }

    public function events(User $user): Builder
    {
        $homes = Household::query()->whereIn('id', $this->scopes->memberHouseholdIds($user))->pluck('area_id');
        $memberAreas = Area::query()->whereIn('id', $homes)->get()->flatMap(fn ($a) => [$a->id, $a->parent_id])->filter()->unique()->all();

        return CommunityEvent::query()->when(! $this->active($user), fn ($q) => $q->whereRaw('1=0'))->where(function ($q) use ($user, $memberAreas): void {
            $q->whereIn('area_id', $memberAreas);
            foreach (['patrol' => 'patrol.manage', 'activity' => 'activities.manage'] as $kind => $permission) {
                $ids = $this->scopes->constrain(Area::query(), $user, $permission)->get()->flatMap(fn ($area) => $kind === 'activity' ? [$area->id, $area->parent_id] : [$area->id])->filter()->unique()->all();
                $q->orWhere(fn ($q) => $q->where('kind', $kind)->whereIn('area_id', $ids));
            }
        });
    }

    public function event(User $user, CommunityEvent $event): bool
    {
        return $this->events($user)->whereKey($event->id)->exists();
    }

    public function participants(User $user, CommunityEvent $event): Builder
    {
        return Participant::query()->where('event_id', $event->id)->when(! $this->event($user, $event), fn ($q) => $q->whereRaw('1=0'))->when(! $this->manages($user, $event), fn ($q) => $q->where('user_id', $user->id));
    }

    public function incident(User $user, Incident $incident): bool
    {
        return $this->active($user) && ($incident->reported_by === $user->id || $this->manages($user, CommunityEvent::query()->findOrFail($incident->event_id)));
    }
}
