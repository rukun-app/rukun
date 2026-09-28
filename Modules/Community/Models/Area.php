<?php

namespace Modules\Community\Models;

use Database\Factories\Community\AreaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(AreaFactory::class)]
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'rukun';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'parent_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
