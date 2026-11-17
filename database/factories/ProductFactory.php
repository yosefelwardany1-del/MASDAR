<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'TP-'.$this->faker->unique()->numerify('#####'),
            'name' => ucfirst($this->faker->words(3, true)),
            'unit' => 'pcs',
            'qty_per_carton' => 48,
            'cbm_per_carton' => '0.0850',
            'kg_per_carton' => '12.5000',
            'active' => true,
        ];
    }
}
