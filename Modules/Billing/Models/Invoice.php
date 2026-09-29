<?php

namespace Modules\Billing\Models;

class Invoice extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'period' => 'date', 'due_date' => 'date', 'settle_by' => 'date'];
    }
}
