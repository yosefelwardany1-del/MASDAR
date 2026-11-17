<?php

namespace Database\Factories;

use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmTask>
 */
class CrmTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'assigned_to' => User::factory(),
            'title' => $this->faker->sentence(4),
            'due_on' => now()->addDays(2)->toDateString(),
            'created_by' => User::factory(),
        ];
    }
}
