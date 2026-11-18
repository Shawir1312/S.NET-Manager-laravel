<?php

namespace Database\Factories;

use App\Models\PppoeCustomer;
use App\Models\Router;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PppoeCustomer> */
class PppoeCustomerFactory extends Factory
{
    public function definition(): array
    {
        $user = fake()->unique()->userName();

        return [
            'router_id' => Router::factory(),
            'pppoe_username' => $user.'@snet',
            'portal_username' => $user,
            'portal_password' => 'portal123',
            'full_name' => fake()->name(),
            'phone' => '0812'.fake()->numerify('########'),
            'profile' => '10Mbps',
            'monthly_price' => 150000,
            'due_day' => 10,
            'status' => 'active',
            'ont_vlan' => 100,
        ];
    }

    public function isolated(): static
    {
        return $this->state(['status' => 'isolated', 'isolated_at' => now(), 'isolated_reason' => 'Telat bayar']);
    }
}
