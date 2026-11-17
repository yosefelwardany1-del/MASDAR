<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierOrder>
 */
class SupplierOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'SO-T-'.$this->faker->unique()->numerify('#####'),
            'supplier_id' => Party::factory()->supplier(),
            'status' => 'open',
            'confirmation_date' => now()->toDateString(),
            'delivery_date' => now()->addDays(30)->toDateString(),
            'currency' => 'CNY',
            'created_by' => User::factory(),
        ];
    }
}
