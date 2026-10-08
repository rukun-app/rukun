<?php

namespace Modules\Realtime;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSubmission;
use Modules\Billing\Models\Receipt;
use Modules\Civic\Models\Announcement;
use Modules\Civic\Models\CivicCase;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Incident;
use Modules\Engagement\Models\Participant;
use Modules\Payments\Models\Payment;
use Modules\Wifi\Models\GallonBenefit;
use Modules\Wifi\Models\GallonClaim;

class EventRegistry
{
    public const TYPES = [
        'civic.updated' => ['schema_version' => 1],
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
        CommunityEvent::class => 'community_event',
        Participant::class => 'event_participant',
        Incident::class => 'event_incident',
        Announcement::class => 'announcement',
        CivicCase::class => 'civic_case',
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
