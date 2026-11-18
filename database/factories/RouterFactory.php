<?php

namespace Database\Factories;

use App\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Router> */
class RouterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'MT-'.fake()->unique()->city(),
            'ip_address' => fake()->unique()->localIpv4(),
            'nas_ip' => '0.0.0.0/0',
            'api_user' => 'admin',
            'api_password' => 'rahasia',
            'api_port' => 8728,
            'radius_secret' => 'radsecret',
            'status' => 'active',
            'default_vlan' => 100,
        ];
    }
}
