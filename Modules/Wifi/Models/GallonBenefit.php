<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class GallonBenefit extends BillingModel
{
    protected $table = 'gallon_benefits';

    protected function casts(): array
    {
        return ['quota' => 'integer', 'available' => 'integer', 'reserved' => 'integer', 'confirmed' => 'integer', 'expires_on' => 'date'];
    }
}
