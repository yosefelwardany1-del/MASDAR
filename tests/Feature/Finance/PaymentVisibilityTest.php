<?php

namespace Tests\Feature\Finance;

use App\Models\Party;
use App\Security\Roles;

/** Spec §6's two rules inside the Payments screen. */
class PaymentVisibilityTest extends FinanceTestCase
{
    public function test_a_sales_user_limited_to_own_clients_sees_only_their_receipts(): void
    {
        $sales = $this->user(Roles::SALES);
        $usd = $this->usd();
        $mine = $this->receipt($usd, '100', Party::factory()->client()->create(['account_manager_id' => $sales->id]));
        $theirs = $this->receipt($usd, '200', Party::factory()->client()->create());
        $supplier = $this->supplierPayment($usd, '300');
        $this->setOptions(['payments_out_require_approval' => false]);
        $shipping = $this->payments()->record([
            'kind' => 'shipping_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '40',
            'party_id' => Party::factory()->shippingCompany()->create()->id,
        ], $this->user(Roles::ACCOUNTANT));

        $this->actingAs($sales)->get(route('payments.index'))->assertOk()
            ->assertSee($mine->number)->assertDontSee($theirs->number)->assertDontSee($supplier->number)->assertDontSee($shipping->number);
        $this->actingAs($sales)->get(route('payments.show', $mine))->assertOk();
        foreach ([$theirs, $supplier, $shipping] as $hidden) {
            $this->actingAs($sales)->get(route('payments.show', $hidden))->assertForbidden();
        }
        $this->actingAs($sales)->get(route('money-accounts.show', $usd))->assertOk()
            ->assertSee($mine->number)->assertDontSee($theirs->number)->assertDontSee($supplier->number);
    }

    public function test_without_supplier_prices_no_supplier_payment_is_shown(): void
    {
        $this->setOptions(['sales_own_clients_only' => false]);
        $sales = $this->user(Roles::SALES);
        $usd = $this->usd();
        $receipt = $this->receipt($usd, '100');
        $supplier = $this->supplierPayment($usd, '300');

        $this->actingAs($sales)->get(route('payments.index'))->assertOk()->assertSee($receipt->number)->assertDontSee($supplier->number);
        $this->actingAs($sales)->get(route('payments.index', ['kind' => 'supplier_payment']))->assertOk()->assertDontSee($supplier->number);
        $this->actingAs($sales)->get(route('payments.show', $supplier))->assertForbidden();

        $this->setOptions(['sales_see_supplier_prices' => true]);
        $this->actingAs($sales)->get(route('payments.index'))->assertOk()->assertSee($supplier->number);

        $this->actingAs($this->user(Roles::ACCOUNTANT))->get(route('payments.index'))->assertOk()->assertSee($supplier->number)->assertSee($receipt->number);
    }

    public function test_a_user_limited_to_own_clients_records_only_their_receipts(): void
    {
        $sales = $this->user(Roles::SALES);
        $sales->givePermissionTo('payments.edit');
        $usd = $this->usd();
        $other = Party::factory()->client()->create();

        $this->actingAs($sales)->get(route('payments.create', ['kind' => 'supplier_payment']))->assertForbidden();
        $this->actingAs($sales)->get(route('payments.transfer.create'))->assertForbidden();
        $this->actingAs($sales)->post(route('payments.store'), [
            'kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '5', 'party_id' => $other->id,
        ])->assertForbidden();
    }
}
