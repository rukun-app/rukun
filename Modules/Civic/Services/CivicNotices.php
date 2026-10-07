<?php

namespace Modules\Civic\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notifications\Notifications\CoreNotification;
use Modules\Realtime\EventRegistry;
use Modules\Realtime\Events\UserEventCreated;
use Modules\Realtime\Models\UserEvent;
use Modules\Settings\Settings;

class CivicNotices
{
    public static function send(Model $subject, array $recipients): void
    {
        foreach (array_unique($recipients) as $id) {
            $user = User::query()->whereKey($id)->where('status', 'active')->first();
            if (! $user || $user->must_change_password) {
                continue;
            }
            // Persist no title, description, category or comment from the private resource.
            $notice = new CoreNotification('civic', 'civic::messages.notice_title', 'civic::messages.notice_message', context: ['resource_id' => $subject->public_id, 'resource_type' => EventRegistry::resourceType($subject)]);
            if (in_array('database', $notice->via($user), true)) {
                DB::connection('rukun')->table(config('database.connections.core.prefix').'notifications')->insert(['id' => (string) Str::uuid(), 'type' => CoreNotification::class, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => json_encode($notice->toArray($user), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            $event = new UserEvent;
            $event->setConnection('rukun')->setTable(config('database.connections.core.prefix').'user_events');
            $event->fill(['user_id' => $id, 'type' => 'civic.updated', 'schema_version' => 1, 'resource_type' => EventRegistry::resourceType($subject), 'resource_id' => $subject->public_id, 'payload' => ['public_id' => $subject->public_id], 'expires_at' => now()->addDays((int) app(Settings::class)->get('realtime.event_retention_days'))])->save();
            DB::connection('rukun')->afterCommit(function () use ($event): void {
                if (config('broadcasting.default') !== 'null') {
                    UserEventCreated::dispatch(UserEvent::query()->findOrFail($event->id));
                }
            });
        }
    }
}
