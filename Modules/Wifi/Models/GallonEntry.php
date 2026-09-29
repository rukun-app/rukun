<?php

namespace Modules\Wifi\Models;

use Modules\Billing\Models\BillingModel;

class GallonEntry extends BillingModel
{
    protected $table = 'gallon_entries';

    protected function casts(): array
    {
        return ['available_delta' => 'integer', 'reserved_delta' => 'integer', 'confirmed_delta' => 'integer'];
    }
}
