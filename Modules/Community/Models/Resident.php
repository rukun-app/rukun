<?php

namespace Modules\Community\Models;

use Database\Factories\Community\ResidentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ResidentFactory::class)]
class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'rukun';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'area_id', 'area_kind', 'household_id', 'user_id', 'nik', 'nik_hash'];

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
        return ['nik' => 'encrypted', 'birth_date' => 'date'];
    }
}
