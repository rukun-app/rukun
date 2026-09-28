<?php

namespace Modules\Community\Models;

use Database\Factories\Community\RoleAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(RoleAssignmentFactory::class)]
class RoleAssignment extends Model
{
    /** @use HasFactory<RoleAssignmentFactory> */
    use HasFactory, HasUuids;

    protected $connection = 'rukun';

    protected $guarded = ['id'];

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
