<?php

namespace Modules\Billing\Models;

class BankAccount extends BillingModel
{
    protected $table = 'billing_bank_accounts';

    protected function casts(): array
    {
        return [];
    }
}
