<?php

namespace Modules\Realtime;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\Receipt;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Payments\Models\Payment;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Models\GallonClaim;

class EventRegistry
{
    public const TYPES = [
        'wifi.benefit.updated' => ['schema_version' => 1],
        'wifi.claim.updated' => ['schema_version' => 1],
        'invoice.created' => ['schema_version' => 1],
        'invoice.due_soon' => ['schema_version' => 1],
        'payment_submission.created' => ['schema_version' => 1],
        'payment_submission.approved' => ['schema_version' => 1],
        'payment_submission.rejected' => ['schema_version' => 1],
        'receipt.created' => ['schema_version' => 1],

        'notification.created' => ['schema_version' => 1],
        'payment.updated' => ['schema_version' => 1],
        'data_transfer.updated' => ['schema_version' => 1],
    ];

    public const RESOURCES = [
        GallonBenefit::class => 'gallon_benefit',
        GallonClaim::class => 'gallon_claim',
        Invoice::class => 'invoice',
        PaymentSubmission::class => 'payment_submission',
        Receipt::class => 'receipt',
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
