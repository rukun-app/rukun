<?php

namespace Modules\Billing\Models;

class ReceiptReversal extends BillingModel
{
    protected function casts(): array
    {
        return ['posted_on' => 'date'];
    }
}
