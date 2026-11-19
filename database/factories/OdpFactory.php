<?php

namespace Database\Factories;

use App\Models\Odp;
use App\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Odp> */
class OdpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'router_id' => Router::factory(),
            'name' => 'ODP-'.fake()->unique()->numerify('###'),
            'latitude' => fake()->latitude(0.7, 0.8),
            'longitude' => fake()->longitude(127.3, 127.4),
            'capacity' => 16,
        ];
    }
}
