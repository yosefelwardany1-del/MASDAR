<?php

namespace Database\Factories;

use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'T-'.$this->faker->unique()->numerify('#####'),
            'name' => $this->faker->company(),
            'is_client' => true,
            'country' => 'AE',
            'active' => true,
        ];
    }

    public function client(): static
    {
        return $this->state(['is_client' => true, 'is_supplier' => false, 'is_shipping' => false]);
    }

    public function supplier(): static
    {
        return $this->state(['is_client' => false, 'is_supplier' => true, 'is_shipping' => false, 'country' => 'CN']);
    }

    public function shippingCompany(): static
    {
        return $this->state(['is_client' => false, 'is_supplier' => false, 'is_shipping' => true, 'country' => 'CN', 'shipping_kind' => 'forwarder']);
    }
}
