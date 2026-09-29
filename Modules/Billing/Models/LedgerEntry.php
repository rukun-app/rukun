<?php

namespace Modules\Billing\Models;

class LedgerEntry extends BillingModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'posted_on' => 'date'];
    }
}
