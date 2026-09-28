<?php

namespace Modules\Realtime;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

class EventRegistry
{
    public const TYPES = [
        'notification.created' => ['schema_version' => 1],
    ];

    public const RESOURCES = [
        DatabaseNotification::class => 'notification',
    ];

    public static function validate(string $type): void
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw new \InvalidArgumentException("Unregistered realtime event type: {$type}");
        }
    }

    public static function resourceType(Model $resource): string
    {
        return self::RESOURCES[$resource::class]
            ?? throw new \InvalidArgumentException('Unregistered realtime resource type: '.$resource::class);
    }
}
