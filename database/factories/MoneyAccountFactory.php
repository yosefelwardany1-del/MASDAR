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
            // Fixtures pay out of accounts nobody funded first; an account made on the screen
            // never goes below zero unless switched (B06). strict() gives a test that rule.
            'allow_negative' => true,
        ];
    }

    /** An account that refuses money out it does not hold, as every account does by default (B06). */
    public function strict(): static
    {
        return $this->state(['allow_negative' => false]);
    }

    public function rmb(): static
    {
        return $this->state(['currency' => 'CNY', 'location' => 'mainland', 'ledger_account_id' => Account::where('code', '1201')->value('id')]);
    }
}
