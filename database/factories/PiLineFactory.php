<?php

namespace Database\Factories;

use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PiLine>
 */
class PiLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'proforma_invoice_id' => ProformaInvoice::factory(),
            'line_no' => $this->faker->unique()->numberBetween(1, 100000),
            'product_id' => Product::factory(),
            'description' => $this->faker->sentence(4),
            'unit' => 'pcs',
            'cartons' => 10,
            'qty_per_carton' => 48,
            'unit_price' => '1.2500',
            'cbm_per_carton' => '0.0850',
            'kg_per_carton' => '12.5000',
        ];
    }
}
