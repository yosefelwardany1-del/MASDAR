<?php

namespace Database\Factories;

use App\Models\Container;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Container>
 */
class ContainerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'container_no' => strtoupper($this->faker->lexify('????')).$this->faker->numerify('#######'),
            'container_type' => '40HQ',
        ];
    }
}
