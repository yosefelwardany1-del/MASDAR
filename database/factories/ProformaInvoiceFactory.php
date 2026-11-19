<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProformaInvoice>
 */
class ProformaInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pi_number' => 'PI-T-'.$this->faker->unique()->numerify('#####'),
            'revision' => 1,
            'is_current' => true,
            'status' => 'draft',
            'client_id' => Party::factory()->client(),
            'currency' => 'USD',
            'deposit_percent' => 30,
            'created_by' => User::factory(),
        ];
    }

    /** Issue it the way the service does: lines first (a draft), then the status. */
    public function issued(): static
    {
        return $this->afterCreating(function (ProformaInvoice $pi) {
            $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);
        });
    }

    /**
     * Issued, then confirmed: the sales order purchase orders are made from. With no deposit asked
     * (0%), as a fixture records no payment and a deposit must be paid to confirm (B03).
     */
    public function confirmed(): static
    {
        return $this->state(['deposit_percent' => 0])->afterCreating(function (ProformaInvoice $pi) {
            $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);
            $pi->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        });
    }
}
