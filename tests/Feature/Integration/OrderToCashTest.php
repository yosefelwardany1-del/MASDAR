<?php

namespace Tests\Feature\Integration;

use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\MoneyAccount;
use App\Models\OrderStepDefinition;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingService;
use App\Services\Finance\FxRateService;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use App\Services\Parties\PartyService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShipmentService;
use App\Services\SupplierOrders\GoodsReceiptService;
use App\Services\SupplierOrders\OrderStepService;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * One order through every module, the way MASDAR works it: a client's PI, the factory order,
 * the deposit, the goods received and inspected, booked and loaded into a container that
 * sails, the final payment, the factory paid — and the books balance at the end. Each module
 * is tested on its own; this proves they fit.
 */
class OrderToCashTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_an_order_runs_from_pi_to_balanced_books(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $sales = User::factory()->create()->assignRole(Roles::SALES);
        $parties = app(PartyService::class);
        $client = $parties->create(['is_client' => true, 'name' => 'Gulf Home Trading', 'country' => 'AE', 'account_manager_id' => $sales->id], $manager);
        $factory = $parties->create(['is_supplier' => true, 'name' => 'Yiwu Bright Housewares', 'country' => 'CN'], $manager);
        $forwarder = $parties->create(['is_shipping' => true, 'name' => 'Ningbo Fast Forwarding', 'country' => 'CN', 'shipping_kind' => 'forwarder'], $manager);
        $this->assertSame(['C-0001', 'S-0001', 'SC-0001'], [$client->code, $factory->code, $forwarder->code]);

        // 1. The PI, in MASDAR's terms: 12 ctn × 24 pcs × $0.875 + 10 ctn × 48 pcs × $1.25 = 852.00
        $pis = app(ProformaInvoiceService::class);
        $pi = $pis->createDraft(['client_id' => $client->id, 'po_number' => 'GH-77'], $sales);
        foreach ([['Glass jar 500ml', 12, 24, '0.875', '3.10'], ['Steel lunch box', 10, 48, '1.25', '5.60']] as [$item, $ctn, $per, $price, $cost]) {
            $pis->addLine($pi, [
                'product' => 'new', 'description' => $item, 'unit' => 'pcs', 'cartons' => $ctn, 'qty_per_carton' => $per,
                'unit_price' => $price, 'cbm_per_carton' => '0.0800', 'kg_per_carton' => '11', 'supplier_id' => $factory->id,
                'supplier_price' => $cost, 'supplier_currency' => 'CNY',
            ], $manager);
        }
        $pi = $pis->issue($pi->fresh(), $manager);
        $this->assertSame('852.0000', (string) DB::table('pi_lines')->where('proforma_invoice_id', $pi->id)->sum('amount'));
        $this->assertSame('30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING', $pi->terms_text);

        // 2. The client's 30% deposit arrives in Hong Kong (USD).
        $usd = MoneyAccount::factory()->create();
        $rmb = MoneyAccount::factory()->rmb()->create();
        $payments = app(PaymentService::class);
        $payments->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '255.60', 'party_id' => $client->id, 'proforma_invoice_id' => $pi->id], $manager);
        $pis->confirm($pi->fresh(), $manager);

        // 3. The factory order, from the confirmed PI's lines (B01: the sales order).
        $order = app(SupplierOrderService::class)->createFromPi($pi, [[
            'supplier_id' => $factory->id, 'lines' => $pi->lines->mapWithKeys(fn ($l) => [$l->id => null])->all(),
            'confirmation_date' => now()->toDateString(), 'delivery_date' => now()->addDays(25)->toDateString(),
            'currency' => 'CNY', 'merchandiser_id' => $manager->id,
        ]], $manager)->first();

        // 4. Goods received and inspected: the receive-goods step completes itself.
        foreach ($order->lines as $line) {
            app(GoodsReceiptService::class)->receive($line, ['received_on' => now()->toDateString(), 'cartons' => (string) $line->cartons, 'inspection_result' => 'pass'], $manager);
        }
        $steps = app(OrderStepService::class);
        $this->assertTrue($steps->isDone($order, OrderStepDefinition::where('code', 'receive_goods')->first()));

        // 5. Booked and loaded: the booking and loading steps complete themselves.
        $shipment = app(ShipmentService::class)->create(['shipping_company_id' => $forwarder->id, 'mode' => 'sea_fcl', 'port_of_loading' => 'NGB', 'port_of_discharge' => 'JEA', 'etd' => now()->addDays(3)->toDateString(), 'eta' => now()->addDays(28)->toDateString()], $manager);
        $container = app(ShipmentService::class)->addContainer($shipment, ['container_no' => 'MSKU1234567', 'container_type' => '40HQ'], $manager);
        app(ShipmentService::class)->bookOrder($shipment, $order, $manager);
        foreach ($order->lines as $line) {
            app(ContainerTrackingService::class)->load($container, $line, (string) $line->cartons, now()->toDateString(), $manager);
        }
        app(ContainerTrackingService::class)->recordStatus($container, ['status' => 'loaded_sealed', 'happened_at' => now()->toDateTimeString()], $manager);
        app(ContainerTrackingService::class)->recordStatus($container, ['status' => 'on_board', 'happened_at' => now()->toDateTimeString(), 'place' => 'Ningbo'], $manager);
        foreach (['booking', 'loading'] as $code) {
            $this->assertTrue($steps->isDone($order->fresh(), OrderStepDefinition::where('code', $code)->first()), $code);
        }
        $this->assertSame(0, (int) GoodsReceipt::count() - 2);

        // 6. The final 70%, then the factory is paid in RMB once a manager approves, at the day's rate.
        app(FxRateService::class)->set('CNY', now()->toDateString(), '0.140000', $manager);
        $payments->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '596.40', 'party_id' => $client->id, 'proforma_invoice_id' => $pi->id], $manager);
        $this->assertSame(0, bccomp(app(PaymentQueries::class)->paidForPiNumber($pi->pi_number, 'USD'), '852', 4), 'the PI is paid in full');
        $factoryPayment = $payments->record(['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '3580.80', 'rate' => '0.140000', 'party_id' => $factory->id, 'supplier_order_id' => $order->id], $manager);
        $payments->approve($factoryPayment, $manager);

        // 7. The books: invoice the client, bill the factory's goods, post every payment.
        $posting = app(PostingService::class);
        $posting->postClientInvoice($pi->fresh(), $manager);
        $posting->postSupplierBill($order->fresh(), $manager);
        foreach (app(Ledger::class)->effectivePayments()->get() as $payment) {
            $posting->postPayment($payment, $manager);
        }

        $totals = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')->selectRaw('SUM(debit) AS d, SUM(credit) AS c')->first();
        $this->assertSame(0, bccomp((string) $totals->d, (string) $totals->c, 4), 'the books balance');

        $owed = fn (string $code, int $party) => (string) DB::table('journal_lines')->where('account_id', Account::where('code', $code)->value('id'))
            ->where('party_id', $party)->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b');
        $this->assertSame(0, bccomp($owed('1401', $client->id), '0', 4), 'the client owes nothing');
        $this->assertSame(0, bccomp($owed('2201', $factory->id), '0', 4), 'the factory is paid');

        // 8. Every screen of the journey opens for the manager.
        foreach ([
            route('dashboard'), route('proforma-invoices.show', $pi), route('proforma-invoices.print', $pi),
            route('supplier-orders.index'), route('supplier-orders.show', $order), route('supplier-orders.evaluation'),
            route('goods-receipts.index'), route('containers.index'), route('containers.show', $container),
            route('containers.packing-list', $container), route('shipments.show', $shipment), route('payments.index'),
            route('money-accounts.index'), route('accounting.index'), route('finance-reports.index'),
            route('clients.show', $client), route('suppliers.show', $factory), route('shipping-companies.show', $forwarder),
        ] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }
    }
}
