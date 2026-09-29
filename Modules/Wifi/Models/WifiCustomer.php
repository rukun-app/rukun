<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class WifiCustomer extends BillingModel
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
