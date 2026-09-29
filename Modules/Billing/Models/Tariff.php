<?php

namespace Modules\Billing\Models;

class Tariff extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'starts_at' => 'date', 'ends_at' => 'date'];
    }
}
