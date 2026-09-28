<?php

namespace Modules\Community\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HouseholdMembership extends Model
{
    use HasUuids;

    protected $connection = 'rukun';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'resident_id', 'household_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
