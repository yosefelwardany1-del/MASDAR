<?php

namespace Tests\Feature\Finance;

use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use App\Services\Finance\PaymentQueries;
use Illuminate\Validation\ValidationException;

/** B05: a payment against a document never pays more than is left on it. */
class OverpaymentTest extends FinanceTestCase
{
    public function test_a_client_receipt_stops_at_the_pis_balance_and_the_rest_is_an_advance_by_choice(): void
    {
        app()->setLocale('en');
        $usd = $this->usd();
        $pi = ProformaInvoice::factory()->worth('954.72')->issued()->create();
        $this->receipt($usd, '286.42', pi: $pi);

        $this->assertThrows(fn () => $this->receipt($usd, '10000', pi: $pi), ValidationException::class,
            $pi->reference().' has USD 668.30 left to pay. Record at most that on it, and money beyond it on the client without a PI (an advance).');
        $this->actingAs($this->user(Roles::ACCOUNTANT))->post(route('payments.store'), ['kind' => 'client_receipt', 'paid_on' => now()->toDateString(),
            'money_account_id' => $usd->id, 'amount' => '668.31', 'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id])->assertSessionHasErrors('amount');

        // Exactly the balance is accepted; an advance is the same client's receipt without a PI.
        $this->receipt($usd, '668.30', pi: $pi);
        $this->assertSame('954.7200', app(PaymentQueries::class)->paidForPiNumber($pi->pi_number, 'USD'));
        $this->receipt($usd, '9331.70', client: $pi->client);
    }

    public function test_a_supplier_payment_stops_at_what_is_left_on_the_order_awaiting_payments_included(): void
    {
        app()->setLocale('en');
        $cny = $this->cny();
        $this->setRate('CNY', now()->toDateString(), '0.14');
        $order = SupplierOrder::factory()->create();
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'cartons' => 10, 'qty_per_carton' => 10, 'unit_price' => '10']); // 1,000
        $this->supplierPayment($cny, '600', $order->supplier, ['supplier_order_id' => $order->id]); // awaiting approval, still counted

        $this->assertThrows(fn () => $this->supplierPayment($cny, '400.01', $order->supplier, ['supplier_order_id' => $order->id]), ValidationException::class,
            $order->number.' has CNY 400.00 left to pay. A payment against it cannot be more.');
        $this->supplierPayment($cny, '400', $order->supplier, ['supplier_order_id' => $order->id]);
    }
}
