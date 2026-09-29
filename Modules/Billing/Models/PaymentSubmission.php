<?php

namespace Modules\Billing\Models;

class PaymentSubmission extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'transferred_at' => 'date', 'allocations' => 'array', 'reviewed_at' => 'datetime'];
    }
}
