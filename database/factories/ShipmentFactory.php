<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'SH-T-'.$this->faker->unique()->numerify('#####'),
            'shipping_company_id' => Party::factory()->shippingCompany(),
            'mode' => 'sea_fcl',
            'port_of_loading' => 'NGB',
            'port_of_discharge' => 'JEA',
            'etd' => now()->addDays(10)->toDateString(),
            'eta' => now()->addDays(35)->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
