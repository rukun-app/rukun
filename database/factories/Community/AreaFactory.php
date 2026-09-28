<?php

namespace Database\Factories\Community;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Community\Models\Area;

/** @extends Factory<Area> */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return ['kind' => 'rw', 'code' => fake()->unique()->numerify('#####'), 'name' => 'RW '.fake()->word()];
    }

    public function rt(?Area $parent = null): static
    {
        return $this->state(fn () => ['kind' => 'rt', 'parent_id' => $parent?->id ?? Area::factory(), 'name' => 'RT '.fake()->word()]);
    }
}
