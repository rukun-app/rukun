<?php

namespace Modules\Realtime\Services;

use App\Models\User;
use Core\Support\CorrelationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Realtime\Contracts\RealtimePublisher;
use Modules\Realtime\EventRegistry;
use Modules\Realtime\Events\UserEventCreated;
use Modules\Realtime\Models\UserEvent;
use Modules\Settings\Settings;

class DatabaseRealtimePublisher implements RealtimePublisher
{
    public function __construct(private Settings $settings) {}

    public function publish(User $user, string $type, array $payload, ?Model $resource = null): void
    {
        EventRegistry::validate($type);
        $payload = ['request_id' => app(CorrelationContext::class)->id(), ...$payload];

        $store = function () use ($user, $type, $payload, $resource): void {
            $event = UserEvent::query()->create([
                'user_id' => $user->getKey(),
                'type' => $type,
                'schema_version' => 1,
                'resource_type' => $resource ? EventRegistry::resourceType($resource) : null,
                'resource_id' => $resource?->getKey(),
                'payload' => $payload,
                'expires_at' => now()->addDays((int) $this->settings->get('realtime.event_retention_days')),
            ]);

            if (config('broadcasting.default') !== 'null') {
                UserEventCreated::dispatch($event);
            }
        };

        DB::afterCommit($store);
    }
}
