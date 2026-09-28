<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
