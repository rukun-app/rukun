<?php

namespace Modules\Realtime\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEvent extends Model
{
    use HasUlids;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (UserEvent $event): void {
            $event->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function envelope(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'schema_version' => $this->schema_version,
            'resource' => $this->resource_type ? ['type' => $this->resource_type, 'id' => $this->resource_id] : null,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
