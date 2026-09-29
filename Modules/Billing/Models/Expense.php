<?php

namespace Modules\Billing\Models;

class Expense extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'posted_on' => 'date', 'approved_at' => 'datetime'];
    }
}
