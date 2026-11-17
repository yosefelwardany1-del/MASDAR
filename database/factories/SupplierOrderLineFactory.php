<?php

namespace Database\Factories;

use App\Models\PiLine;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierOrderLine>
 */
class SupplierOrderLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_order_id' => SupplierOrder::factory(),
            'pi_line_id' => PiLine::factory(),
            'cartons' => 10,
            'qty_per_carton' => 48,
            'unit_price' => '6.5000',
        ];
    }
}
