<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class GallonClaim extends BillingModel
{
    protected $table = 'gallon_claims';

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
