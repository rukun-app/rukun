<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Resident;
use Modules\Realtime\EventRegistry;
use Modules\Realtime\Events\UserEventCreated;
use Modules\Realtime\Models\UserEvent;
use Modules\Settings\Settings;

class BillingEvents
{
    public static function emit(string $type, Model $subject, ?int $householdId, array $extraUsers = []): void
    {
        EventRegistry::validate($type);
        $recipients = $householdId ? Resident::query()->where('household_id', $householdId)->where('status', 'active')->whereNotNull('user_id')->pluck('user_id')->all() : [];
        foreach (array_unique([...$recipients, ...$extraUsers]) as $id) {
            if (! User::query()->whereKey($id)->where('status', 'active')->exists()) {
                continue;
            }
            $event = new UserEvent;
            $event->setConnection('rukun')->setTable(config('database.connections.core.prefix').'user_events');
            $event->fill(['user_id' => $id, 'type' => $type, 'schema_version' => 1, 'resource_type' => EventRegistry::resourceType($subject), 'resource_id' => $subject->public_id, 'payload' => ['public_id' => $subject->public_id], 'expires_at' => now()->addDays((int) app(Settings::class)->get('realtime.event_retention_days'))])->save();
            DB::connection('rukun')->afterCommit(function () use ($event): void {
                if (config('broadcasting.default') !== 'null') {
                    UserEventCreated::dispatch(UserEvent::query()->findOrFail($event->id));
                }
            });
        }
    }
}
