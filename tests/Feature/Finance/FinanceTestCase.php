<?php

namespace Tests\Feature\Finance;

use App\Models\BusinessSetting;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Finance\FxRateService;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Shared set-up for the money module's tests (module 5). */
abstract class FinanceTestCase extends TestCase
{
    use RefreshDatabase, SeedsReference;

    protected function user(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    protected function usd(): MoneyAccount
    {
        return MoneyAccount::factory()->create(['name' => 'HSBC USD']);
    }

    protected function cny(): MoneyAccount
    {
        return MoneyAccount::factory()->rmb()->create(['name' => 'ICBC Yiwu RMB']);
    }

    protected function setOptions(array $values): void
    {
        BusinessSetting::current()->fill($values)->save();
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function setRate(string $currency, string $date, string $rate): void
    {
        app(FxRateService::class)->set($currency, $date, $rate, $this->user(Roles::ACCOUNTANT));
    }

    protected function receipt(MoneyAccount $account, string $amount, ?Party $client = null, ?ProformaInvoice $pi = null, array $extra = []): Payment
    {
        $client ??= $pi?->client ?? Party::factory()->client()->create();

        return $this->payments()->record([
            'kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => $account->id,
            'amount' => $amount, 'party_id' => $client->id, 'proforma_invoice_id' => $pi?->id,
        ] + $extra, $this->user(Roles::ACCOUNTANT));
    }

    protected function supplierPayment(MoneyAccount $account, string $amount, ?Party $supplier = null, array $extra = []): Payment
    {
        return $this->payments()->record([
            'kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $account->id,
            'amount' => $amount, 'party_id' => ($supplier ?? Party::factory()->supplier()->create())->id,
        ] + $extra, $this->user(Roles::ACCOUNTANT));
    }

    /** An issued revision 1, superseded by an issued revision 2 of the same PI number. */
    protected function piWithTwoRevisions(): array
    {
        $first = ProformaInvoice::factory()->worth('5000')->issued()->create(['pi_number' => 'PI-2026-0042']);
        $first->update(['status' => 'superseded', 'is_current' => false]);
        $second = ProformaInvoice::factory()->worth('5000')->issued()->create([
            'pi_number' => 'PI-2026-0042', 'revision' => 2, 'client_id' => $first->client_id, 'created_by' => $first->created_by,
        ]);

        return [$first->fresh(), $second->fresh()];
    }
}
