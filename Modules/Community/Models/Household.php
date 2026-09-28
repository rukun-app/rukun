<?php

namespace Modules\Community\Models;

use Database\Factories\Community\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(HouseholdFactory::class)]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'rukun';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'area_id', 'area_kind', 'kk_number', 'kk_hash'];

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
        return ['kk_number' => 'encrypted'];
    }
}
