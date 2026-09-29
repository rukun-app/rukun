<?php

namespace Modules\Billing\Models;

class AccountingPeriod extends BillingModel
{
    protected function casts(): array
    {
        return ['period' => 'date', 'report' => 'array', 'closed_at' => 'datetime'];
    }
}
