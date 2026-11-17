<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MoneyAccount>
 */
class MoneyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Test account '.$this->faker->unique()->numerify('###'),
            'kind' => 'bank',
            'currency' => 'USD',
            'location' => 'hong_kong',
            'ledger_account_id' => Account::where('code', '1101')->value('id'),
            'active' => true,
        ];
    }

    public function rmb(): static
    {
        return $this->state(['currency' => 'CNY', 'location' => 'mainland', 'ledger_account_id' => Account::where('code', '1201')->value('id')]);
    }
}
