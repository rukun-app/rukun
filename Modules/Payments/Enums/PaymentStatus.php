<?php

namespace Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Creating = 'creating';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';
    case ProviderUnknown = 'provider_unknown';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Chargeback = 'chargeback';
    case PartialChargeback = 'partial_chargeback';

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
