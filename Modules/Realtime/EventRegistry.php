<?php

namespace Modules\Realtime;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Payments\Models\Payment;

class EventRegistry
{
    public const TYPES = [
        'notification.created' => ['schema_version' => 1],
        'payment.updated' => ['schema_version' => 1],
        'data_transfer.updated' => ['schema_version' => 1],
    ];

    public const RESOURCES = [
        DatabaseNotification::class => 'notification',
        Payment::class => 'payment',
        DataTransfer::class => 'data_transfer',
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
