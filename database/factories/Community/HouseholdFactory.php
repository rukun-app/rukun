<?php

namespace Database\Factories\Community;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;

/** @extends Factory<Household> */
class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return ['reference' => fake()->uuid(), 'area_id' => Area::factory()->rt(), 'address' => fake()->streetAddress(), 'occupancy_status' => 'occupied', 'status' => 'active'];
    }
}
