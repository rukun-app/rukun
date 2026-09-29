<?php

namespace Modules\Billing\Models;

class Receipt extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date'];
    }
}
