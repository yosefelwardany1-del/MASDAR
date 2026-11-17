<?php

namespace Tests\Feature\Shipping;

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\Shipping\ShipmentService;

/**
 * Builds the documents the container follow-up works on. Other modules' writers are built in
 * parallel, so PIs, supplier orders, receipts and payments are written straight to their
 * tables here — as the invariant tests do — while everything of module 4 goes through its
 * services or screens.
 */
trait ShippingFixtures
{
    protected function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['locale' => 'en'])->assignRole($role);
    }

    /**
     * An issued PI for $client with one line per entry of $lines, and one supplier order
     * buying all of them. Each entry: cartons, cbm_per_carton, kg_per_carton, unit_price, name.
     *
     * @return array{0: ProformaInvoice, 1: SupplierOrder, 2: list<SupplierOrderLine>}
     */
    protected function orderFor(?Party $client = null, array $lines = [[]]): array
    {
        $client ??= Party::factory()->client()->create();
        $pi = ProformaInvoice::factory()->create(['client_id' => $client->id]);
        $order = SupplierOrder::factory()->create();
        $orderLines = [];
        foreach (array_values($lines) as $i => $spec) {
            $spec += ['cartons' => 10, 'cbm' => '0.5000', 'kg' => '10.0000', 'price' => '100.0000', 'name' => 'Item '.($i + 1)];
            $piLine = PiLine::factory()->create([
                'proforma_invoice_id' => $pi->id, 'line_no' => $i + 1,
                'product_id' => Product::factory()->create(['name' => $spec['name']])->id,
                'description' => $spec['name'].' description',
                'cartons' => $spec['cartons'], 'qty_per_carton' => 12, 'unit_price' => $spec['price'],
                'cbm_per_carton' => $spec['cbm'], 'kg_per_carton' => $spec['kg'],
            ]);
            $orderLines[] = SupplierOrderLine::factory()->create([
                'supplier_order_id' => $order->id, 'pi_line_id' => $piLine->id, 'cartons' => $spec['cartons'], 'qty_per_carton' => 12,
            ]);
        }
        $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);

        return [$pi, $order, $orderLines];
    }

    protected function receive(SupplierOrderLine $line, int|string $cartons): GoodsReceipt
    {
        return GoodsReceipt::create([
            'supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => $cartons,
            'inspection_result' => 'pass', 'user_id' => User::factory()->create()->id,
        ]);
    }

    protected function pay(ProformaInvoice $pi, string $amount): Payment
    {
        return Payment::create([
            'number' => 'PAY-T-'.$pi->id.'-'.random_int(1000, 9999), 'paid_on' => now()->toDateString(), 'kind' => 'client_receipt',
            'direction' => 'in', 'money_account_id' => MoneyAccount::factory()->create()->id, 'currency' => $pi->currency,
            'amount' => $amount, 'rate' => 1, 'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    protected function shipmentWith(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes);
    }

    protected function containerOn(Shipment $shipment, array $attributes = []): Container
    {
        return Container::factory()->create(['shipment_id' => $shipment->id] + $attributes);
    }

    protected function book(Shipment $shipment, SupplierOrder $order, User $by): void
    {
        app(ShipmentService::class)->bookOrder($shipment, $order, $by);
    }
}
