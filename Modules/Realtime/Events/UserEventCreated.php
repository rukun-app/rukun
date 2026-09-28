<?php

namespace Modules\Realtime\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Realtime\Models\UserEvent;

class UserEventCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $queue = 'high';

    public function __construct(public UserEvent $event) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->event->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'core.event';
    }

    public function broadcastWith(): array
    {
        return $this->event->envelope();
    }
}
