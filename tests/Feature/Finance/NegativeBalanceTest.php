<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Security\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** B06: money out never takes an account below zero, unless the account may go there. */
class NegativeBalanceTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->setRate('CNY', now()->toDateString(), '0.14');
    }

    private function strictCny(string $name = 'Yiwu cash'): MoneyAccount
    {
        return MoneyAccount::factory()->rmb()->strict()->create(['name' => $name]);
    }

    public function test_a_payment_out_of_an_empty_account_is_refused_when_recorded(): void
    {
        $cny = $this->strictCny();
        $this->assertThrows(fn () => $this->supplierPayment($cny, '1000'), ValidationException::class,
            'Yiwu cash holds CNY 0.00 (money out awaiting approval counted as gone), not enough for CNY 1,000.00. Record the money coming in first, or pay from another account.');

        // Through the form: the message is next to the amount.
        $this->actingAs($this->user(Roles::ACCOUNTANT))->post(route('payments.store'), ['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(),
            'money_account_id' => $cny->id, 'amount' => '1000', 'party_id' => Party::factory()->supplier()->create()->id])->assertSessionHasErrors('amount');

        // Money in first, then out up to what is there; payments awaiting approval count as gone.
        $this->receipt($cny, '1500');
        $this->supplierPayment($cny, '1000');
        $this->assertThrows(fn () => $this->supplierPayment($cny, '500.01'), ValidationException::class);
        $this->supplierPayment($cny, '500');

        // A transfer out is held to the same rule.
        $usd = MoneyAccount::factory()->strict()->create(['name' => 'HSBC USD']);
        $this->receipt($usd, '286.42');
        $this->assertThrows(fn () => $this->payments()->transfer(['from_account_id' => $usd->id, 'to_account_id' => $cny->id, 'paid_on' => now()->toDateString(),
            'amount' => '9999999', 'in_amount' => '1'], $this->user(Roles::MANAGER)), ValidationException::class, 'HSBC USD holds USD 286.42');

        // And the database refuses it whatever the caller.
        DB::beginTransaction();
        try {
            DB::table('payments')->insert(['number' => 'PAY-X', 'paid_on' => now()->toDateString(), 'kind' => 'expense', 'direction' => 'out',
                'money_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '300', 'rate' => '1', 'user_id' => $this->user(Roles::MANAGER)->id,
                'counter_account_id' => Account::where('code', '6601')->value('id')]);
            $this->fail('the database let a payment overdraw its account');
        } catch (QueryException $e) {
            $this->assertStringContainsString('below zero', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_approval_checks_the_balance_again(): void
    {
        $cny = $this->strictCny();
        $receipt = $this->receipt($cny, '1000');
        $payment = $this->supplierPayment($cny, '800');

        // The receipt turns out to be a mistake: reversing it would leave the payment uncovered.
        $this->assertThrows(fn () => $this->payments()->reverse($receipt, $this->user(Roles::ACCOUNTANT), 'Bounced'), ValidationException::class, 'holds CNY 200.00');

        // An account that may go below zero (a payment agent) is not held to it.
        $agent = MoneyAccount::factory()->rmb()->create(['name' => 'Agent Chen', 'allow_negative' => true]);
        $this->payments()->approve($this->supplierPayment($agent, '5000'), $this->user(Roles::MANAGER));

        // A legacy overdraft (written before this rule) blocks approvals until it is put right.
        DB::statement('ALTER TABLE payments DISABLE TRIGGER payments_never_overdraw');
        DB::table('payments')->insert(['number' => 'PAY-OLD', 'paid_on' => now()->toDateString(), 'kind' => 'expense', 'direction' => 'out',
            'money_account_id' => $cny->id, 'currency' => 'CNY', 'amount' => '300', 'rate' => '0.14', 'user_id' => $this->user(Roles::MANAGER)->id,
            'counter_account_id' => Account::where('code', '6601')->value('id')]);
        DB::statement('ALTER TABLE payments ENABLE TRIGGER payments_never_overdraw');
        $this->assertThrows(fn () => $this->payments()->approve($payment, $this->user(Roles::MANAGER)), ValidationException::class, 'holds CNY -100.00');
    }
}
