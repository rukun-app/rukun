<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class WifiFinance extends BillingModel
{
    protected $table = 'wifi_finance';

    protected function casts(): array
    {
        return ['amount' => 'integer', 'advance' => 'integer', 'posted_on' => 'date'];
    }
}
