<?php

namespace Database\Factories\Community;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Community\Models\Area;
use Modules\Community\Models\RoleAssignment;
use Spatie\Permission\Models\Role;

/** @extends Factory<RoleAssignment> */
class RoleAssignmentFactory extends Factory
{
    protected $model = RoleAssignment::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'role_id' => fn () => Role::findOrCreate('ketua-rt', 'web')->id,
            'scope_type' => 'rt', 'area_id' => Area::factory()->rt(), 'starts_at' => now()->subDay(), 'assigned_by' => User::factory()];
    }
}
