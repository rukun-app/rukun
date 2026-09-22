<?php

namespace Core\Audit;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
