<?php

namespace Modules\Files\Models;

use App\Models\User;
use Database\Factories\StoredFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'files';

    protected $guarded = [];

    protected $hidden = ['id', 'disk', 'path', 'owner_id', 'checksum', 'deleted_at'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'file_id');
    }

    protected static function newFactory(): StoredFileFactory
    {
        return StoredFileFactory::new();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size' => 'integer',
        ];
    }
}
