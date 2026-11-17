<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\PostingService;
use App\Services\Finance\FxRateService;
use App\Services\Finance\MoneyAccountService;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use App\Services\Parties\PartyService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShipmentService;
use App\Services\SupplierOrders\GoodsReceiptService;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Example data to show MASDAR the system (local and staging only): clients, factories and a
 * forwarder, two PIs, their factory orders part-way through the follow-up, one container at
 * sea, the payments, and the journal entries for what is ready to post. Everything goes through the real writers, so it is audited and obeys
 * every rule. Run after DemoUsersSeeder. Refuses to run in production.
 */
class MasdarDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('MasdarDemoSeeder never runs in production.');
        }
        $manager = User::role(Roles::MANAGER)->first() ?? throw new RuntimeException('Run DemoUsersSeeder first.');
        $sales = User::role(Roles::SALES)->first() ?? $manager;
        $merch = User::role(Roles::MERCHANDISER)->first() ?? $manager;
        Auth::setUser($manager);

        $parties = app(PartyService::class);
        $clients = [
            $parties->create(['is_client' => true, 'name' => 'Gulf Home Trading LLC', 'country' => 'AE', 'city' => 'Dubai', 'account_manager_id' => $sales->id, 'default_destination' => 'Jebel Ali, UAE', 'stage' => 'confirmed', 'source' => 'exhibition'], $manager),
            $parties->create(['is_client' => true, 'name' => 'Nile Kitchenware Co.', 'country' => 'EG', 'city' => 'Cairo', 'account_manager_id' => $sales->id, 'default_destination' => 'Alexandria, Egypt', 'stage' => 'pi_sent', 'source' => 'referral'], $manager),
        ];
        $factories = [
            $parties->create(['is_supplier' => true, 'name' => 'Yiwu Bright Housewares Factory', 'name_local' => '义乌明亮家居用品厂', 'country' => 'CN', 'city' => 'Yiwu'], $manager),
            $parties->create(['is_supplier' => true, 'name' => 'Jinhua Steelware Co., Ltd.', 'name_local' => '金华钢制品有限公司', 'country' => 'CN', 'city' => 'Jinhua'], $manager),
        ];
        $forwarder = $parties->create(['is_shipping' => true, 'name' => 'Ningbo Fast Forwarding', 'name_local' => '宁波快运货代', 'country' => 'CN', 'shipping_kind' => 'forwarder'], $manager);

        $pis = app(ProformaInvoiceService::class);
        $make = function ($client, array $lines, ?string $po) use ($pis, $sales, $manager) {
            $pi = $pis->createDraft(['client_id' => $client->id, 'po_number' => $po, 'po_date' => now()->subDays(10)->toDateString()], $sales);
            foreach ($lines as [$item, $ctn, $per, $price, $cost, $factory]) {
                $pis->addLine($pi, ['product' => 'new', 'description' => $item, 'unit' => 'pcs', 'cartons' => $ctn, 'qty_per_carton' => $per,
                    'unit_price' => $price, 'cbm_per_carton' => '0.0820', 'kg_per_carton' => '11.5', 'supplier_id' => $factory->id,
                    'supplier_price' => $cost, 'supplier_currency' => 'CNY'], $manager);
            }

            return $pis->issue($pi->fresh(), $manager);
        };
        $pi1 = $make($clients[0], [
            ['Glass storage jar 500ml', 120, 24, '0.875', '3.10', $factories[0]],
            ['Glass storage jar 1L', 80, 24, '1.150', '4.20', $factories[0]],
            ['Stainless steel lunch box', 100, 48, '1.250', '5.60', $factories[1]],
        ], 'GH-2026-077');
        $make($clients[1], [['Ceramic mug 350ml', 150, 36, '0.640', '2.30', $factories[0]]], 'NK-114');

        $orders = app(SupplierOrderService::class)->createFromPi($pi1, collect($factories)->map(fn ($f) => [
            'supplier_id' => $f->id,
            'lines' => $pi1->lines->where('supplier_id', $f->id)->mapWithKeys(fn ($l) => [$l->id => null])->all(),
            'confirmation_date' => now()->subDays(20)->toDateString(), 'delivery_date' => now()->addDays(6)->toDateString(),
            'currency' => 'CNY', 'merchandiser_id' => $merch->id,
        ])->all(), $manager);

        $accounts = app(MoneyAccountService::class);
        $usd = MoneyAccount::where('name', 'HSBC Hong Kong — USD')->first()
            ?? $accounts->create(['name' => 'HSBC Hong Kong — USD', 'kind' => 'bank', 'currency' => 'USD', 'location' => 'hong_kong', 'ledger_account_id' => Account::where('code', '1101')->value('id'), 'active' => true]);
        $rmb = MoneyAccount::where('name', 'Yiwu bank — RMB')->first()
            ?? $accounts->create(['name' => 'Yiwu bank — RMB', 'kind' => 'bank', 'currency' => 'CNY', 'location' => 'mainland', 'ledger_account_id' => Account::where('code', '1201')->value('id'), 'active' => true]);
        app(FxRateService::class)->set('CNY', now()->subDays(20)->toDateString(), '0.140000', $manager);

        $payments = app(PaymentService::class);
        // The client's 30% deposit (of 10,728.00 USD), then RMB for the factories: 1,400 USD → 10,000 CNY at 0.14.
        $payments->record(['kind' => 'client_receipt', 'paid_on' => now()->subDays(18)->toDateString(), 'money_account_id' => $usd->id, 'amount' => '3218.40', 'party_id' => $clients[0]->id, 'proforma_invoice_id' => $pi1->id, 'reference' => 'TT deposit'], $manager);
        $pis->confirm($pi1->fresh(), $manager);
        ['out' => $transferOut] = $payments->transfer(['from_account_id' => $usd->id, 'to_account_id' => $rmb->id, 'paid_on' => now()->subDays(18)->toDateString(), 'amount' => '1400.00', 'in_amount' => '10000.00', 'reference' => 'RMB for factory deposits'], $manager);
        if (! app(PaymentQueries::class)->isEffective($transferOut->fresh())) {
            $payments->approve($transferOut->fresh(), $manager);
        }
        $deposit = $payments->record(['kind' => 'supplier_payment', 'paid_on' => now()->subDays(17)->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '5000.00', 'party_id' => $factories[0]->id, 'supplier_order_id' => $orders[0]->id, 'reference' => 'Deposit'], $manager);
        $payments->approve($deposit, $manager);

        // The first factory's goods are in, booked, loaded and at sea; the second is still in production.
        foreach ($orders[0]->lines as $line) {
            app(GoodsReceiptService::class)->receive($line, ['received_on' => now()->subDays(4)->toDateString(), 'cartons' => (string) $line->cartons, 'inspection_result' => 'pass'], $manager);
        }
        $shipment = app(ShipmentService::class)->create(['shipping_company_id' => $forwarder->id, 'mode' => 'sea_fcl', 'booking_no' => 'NGB-55831', 'port_of_loading' => 'NGB', 'port_of_discharge' => 'JEA', 'etd' => now()->subDays(2)->toDateString(), 'eta' => now()->addDays(2)->toDateString(), 'vessel' => 'MSC AURORA', 'voyage' => '246W'], $manager);
        $container = app(ShipmentService::class)->addContainer($shipment, ['container_no' => 'MSKU7712345', 'container_type' => '40HQ', 'seal_no' => 'ML-449120'], $manager);
        app(ShipmentService::class)->bookOrder($shipment, $orders[0], $manager);
        foreach ($orders[0]->lines as $line) {
            app(ContainerTrackingService::class)->load($container, $line, (string) $line->cartons, now()->subDays(3)->toDateString(), $manager);
        }
        foreach (['loaded_sealed' => 3, 'gate_in' => 2, 'on_board' => 2] as $status => $daysAgo) {
            app(ContainerTrackingService::class)->recordStatus($container, ['status' => $status, 'happened_at' => now()->subDays($daysAgo)->toDateTimeString(), 'place' => 'Ningbo'], $manager);
        }

        // The accountant's queue, cleared: the confirmed PI invoiced, the received order billed, both payments posted,
        // so the journal and the reports have something to show.
        $posting = app(PostingService::class);
        $posting->postClientInvoice($pi1->fresh(), $manager);
        $posting->postSupplierBill($orders[0]->fresh(), $manager);
        Payment::query()->orderBy('id')->get()->each(fn (Payment $payment) => $posting->postPayment($payment, $manager));
    }
}
