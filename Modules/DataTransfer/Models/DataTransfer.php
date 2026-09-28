<?php

namespace Modules\DataTransfer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\Files\Models\StoredFile;

class DataTransfer extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inputFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'input_file_id');
    }

    public function outputFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'output_file_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'options' => 'array',
            'error_summary' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
