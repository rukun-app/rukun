<?php

namespace Modules\Billing\Models;

class GatewayCheckout extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
