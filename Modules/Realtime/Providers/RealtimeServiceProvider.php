<?php

namespace Modules\Realtime\Providers;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Notifications\CoreNotification;
use Modules\Realtime\Console\PruneEventsCommand;
use Modules\Realtime\Contracts\RealtimePublisher;
use Modules\Realtime\Services\DatabaseRealtimePublisher;

class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        Broadcast::routes(['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'active', 'locale']]);
        require base_path('routes/channels.php');

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'database' || ! $event->notification instanceof CoreNotification) {
                return;
            }

            app(RealtimePublisher::class)->publish(
                $event->notifiable,
                'notification.created',
                [
                    'notification_id' => $event->response?->getKey(),
                    'category' => $event->notification->category,
                ],
                $event->response,
            );
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneEventsCommand::class]);
        }
    }

    public function register(): void
    {
        $this->app->bind(RealtimePublisher::class, DatabaseRealtimePublisher::class);
    }
}
