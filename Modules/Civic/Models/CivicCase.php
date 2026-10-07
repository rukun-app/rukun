<?php

namespace Modules\Civic\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CivicCase extends Model
{
    use HasUuids;

    protected $connection = 'rukun';

    protected $table = 'civic_cases';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'submitted', 'version' => 1];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
