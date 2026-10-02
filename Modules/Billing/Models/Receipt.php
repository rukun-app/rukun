<?php

namespace Modules\Billing\Models;

class Receipt extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date', 'paid_at' => 'immutable_datetime'];
    }
}
