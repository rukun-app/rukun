<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class WifiPackage extends BillingModel
{
    protected function casts(): array
    {
        return ['due_day' => 'integer', 'settle_day' => 'integer', 'allow_advance' => 'boolean', 'remit_day' => 'integer', 'gallon_quota' => 'integer', 'claim_days' => 'integer'];
    }
}
