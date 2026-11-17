<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FxRate;
use App\Models\MoneyAccount;
use App\Security\Roles;
use App\Services\Finance\FxRateService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Money accounts (balances, cash position, the currency lock) and FX rates. */
class MoneyAccountAndRateTest extends FinanceTestCase
{
    private function accountPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alipay Yiwu', 'kind' => 'ewallet', 'currency' => 'CNY', 'location' => 'mainland',
            'ledger_account_id' => Account::where('code', '1202')->value('id'), 'details' => 'Alipay account of the office', 'active' => '1',
        ], $overrides);
    }

    public function test_the_accountant_creates_and_edits_a_money_account_and_it_is_audited(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $this->actingAs($this->user(Roles::SALES))->post(route('money-accounts.store'), $this->accountPayload())->assertForbidden();

        $this->actingAs($accountant)->get(route('money-accounts.create'))->assertOk();
        $this->actingAs($accountant)->post(route('money-accounts.store'), $this->accountPayload())->assertRedirect();
        $account = MoneyAccount::where('name', 'Alipay Yiwu')->firstOrFail();
        $this->assertSame(['ewallet', 'CNY', 'mainland', true], [$account->kind, $account->currency, $account->location, $account->active]);
        $this->assertTrue(AuditLog::where('model_type', MoneyAccount::class)->where('model_id', $account->id)->where('action', 'created')->exists());

        $this->actingAs($accountant)->get(route('money-accounts.edit', $account))->assertOk();
        $this->actingAs($accountant)->put(route('money-accounts.update', $account), $this->accountPayload(['name' => 'Alipay office', 'active' => '0']))->assertRedirect();
        $this->assertSame(['Alipay office', false], [$account->fresh()->name, $account->fresh()->active]);
        $this->assertTrue(AuditLog::where('model_type', MoneyAccount::class)->where('action', 'updated')->exists());
    }

    public function test_the_ledger_account_must_be_a_postable_asset_in_the_same_currency(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        foreach (['12', '6101', '1101'] as $code) { // a heading, an expense, a USD bank account
            $this->actingAs($accountant)->post(route('money-accounts.store'), $this->accountPayload(['ledger_account_id' => Account::where('code', $code)->value('id')]))
                ->assertSessionHasErrors('ledger_account_id');
        }
        $this->actingAs($accountant)->post(route('money-accounts.store'), $this->accountPayload(['currency' => 'XXX']))->assertSessionHasErrors('currency');
        $this->assertSame(0, MoneyAccount::count());
    }

    public function test_the_currency_is_locked_once_the_account_has_payments(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $account = MoneyAccount::factory()->create(['ledger_account_id' => null]);
        $payload = ['name' => $account->name, 'kind' => 'bank', 'location' => 'hong_kong', 'active' => '1'];

        $this->actingAs($accountant)->put(route('money-accounts.update', $account), $payload + ['currency' => 'HKD'])->assertRedirect();
        $this->assertSame('HKD', $account->fresh()->currency, 'no payments yet: the currency may change');

        $this->receipt($account->fresh(), '100', extra: ['rate' => '0.128']);
        $this->actingAs($accountant)->get(route('money-accounts.edit', $account))->assertOk()->assertSee(__('This account has payments; its currency cannot change.'));
        $this->actingAs($accountant)->put(route('money-accounts.update', $account), $payload + ['currency' => 'USD'])->assertSessionHasErrors('currency');
        $this->assertSame('HKD', $account->fresh()->currency);

        try {
            DB::transaction(fn () => DB::table('money_accounts')->where('id', $account->id)->update(['currency' => 'USD']));
            $this->fail('the database keeps the currency too');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never changes', $e->getMessage());
        }
    }

    public function test_the_index_shows_balances_and_the_cash_position_and_the_account_its_running_balance(): void
    {
        $this->setOptions(['payments_out_require_approval' => false]);
        $usd = $this->usd();
        $this->receipt($usd, '1000');
        $this->supplierPayment($usd, '250.75');
        $cny = $this->cny();
        $this->receipt($cny, '8000', extra: ['rate' => '0.14']);

        $manager = $this->user(Roles::MANAGER);
        $this->actingAs($manager)->get(route('money-accounts.index'))->assertOk()
            ->assertSee('749.25')->assertSee('8,000.00')->assertSee('HSBC USD')->assertSee('ICBC Yiwu RMB');
        $this->actingAs($manager)->get(route('money-accounts.show', $usd))->assertOk()
            ->assertSee(__('Running balance'))->assertSeeInOrder(['749.25', '1,000.00']);
    }

    public function test_a_rate_is_set_then_updated_for_the_same_day_and_rate_for_takes_the_latest_before(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $this->actingAs($this->user(Roles::SALES))->get(route('fx-rates.index'))->assertOk();
        $this->actingAs($this->user(Roles::SALES))->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => '2026-11-01', 'rate' => '0.14'])->assertForbidden();

        $this->actingAs($accountant)->get(route('fx-rates.create'))->assertOk();
        $this->actingAs($accountant)->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => '2026-11-01', 'rate' => '0.1385'])->assertRedirect();
        $this->actingAs($accountant)->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => '2026-11-01', 'rate' => '0.1390'])->assertRedirect();
        $this->assertSame(1, FxRate::count());
        $rate = FxRate::firstOrFail();
        $this->assertSame('0.139000', $rate->rate);
        $this->assertTrue(AuditLog::where('model_type', FxRate::class)->where('action', 'updated')->exists());

        $this->actingAs($accountant)->get(route('fx-rates.edit', $rate))->assertOk();
        $this->actingAs($accountant)->put(route('fx-rates.update', $rate), ['rate' => '0.1401'])->assertRedirect();
        $this->assertSame('0.140100', $rate->fresh()->rate);
        $this->actingAs($accountant)->get(route('fx-rates.index', ['currency' => 'CNY']))->assertOk()->assertSee('0.1401');

        $this->setRate('CNY', '2026-11-10', '0.141');
        $service = app(FxRateService::class);
        $this->assertNull($service->rateFor('CNY', '2026-10-31'));
        $this->assertSame('0.140100', $service->rateFor('CNY', '2026-11-09'));
        $this->assertSame('0.141000', $service->rateFor('CNY', '2026-11-30'));
        $this->assertNull($service->rateFor('HKD', '2026-11-30'));

        foreach ([['rate' => '0'], ['rate' => '-1'], ['rate' => 'abc'], ['currency' => 'USD'], ['currency' => 'ZZZ']] as $bad) {
            $this->actingAs($accountant)->post(route('fx-rates.store'), $bad + ['currency' => 'CNY', 'rate_date' => '2026-11-02', 'rate' => '0.14'])
                ->assertSessionHasErrors();
        }
        $this->assertSame(2, FxRate::count());
    }
}
