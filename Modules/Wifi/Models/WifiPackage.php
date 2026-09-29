<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class WifiPackage extends BillingModel
{
    protected function casts(): array
    {
        return ['due_day' => 'integer', 'settle_day' => 'integer'];
    }
}
