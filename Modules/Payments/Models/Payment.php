<?php

namespace Modules\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payments\Enums\PaymentStatus;

class Payment extends Model
{
    protected $guarded = [];

    protected $hidden = ['id', 'checkout_token', 'provider_data'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'provider_data' => 'array',
            'paid_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
