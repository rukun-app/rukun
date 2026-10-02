<?php

namespace Modules\Billing\Models;

class GatewaySettlement extends BillingModel
{
    protected function casts(): array
    {
        return ['gross' => 'integer', 'fee' => 'integer', 'net' => 'integer', 'settled_on' => 'date'];
    }
}
