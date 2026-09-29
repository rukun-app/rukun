<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class WifiBill extends BillingModel
{
    protected function casts(): array
    {
        return ['period' => 'date'];
    }
}
