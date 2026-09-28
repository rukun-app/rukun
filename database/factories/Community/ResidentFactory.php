<?php

namespace Database\Factories\Community;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Community\Models\Area;
use Modules\Community\Models\Resident;

/** @extends Factory<Resident> */
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    public function definition(): array
    {
        return ['reference' => fake()->uuid(), 'name' => fake()->name(), 'area_id' => Area::factory()->rt(), 'status' => 'active'];
    }
}
