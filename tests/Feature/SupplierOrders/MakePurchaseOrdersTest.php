<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\BusinessSetting;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B01: the way into purchase orders — from a confirmed PI, one order per factory, on the board. */
class MakePurchaseOrdersTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_a_confirmed_pi_with_two_suppliers_is_offered_and_becomes_two_orders_on_the_board(): void
    {
        $manager = $this->user(Roles::MANAGER)->forceFill(['locale' => 'en']);
        [$alpha, $beta] = [$this->supplier(['name' => 'Alpha Toys']), $this->supplier(['name' => 'Beta Bags'])];
        $pi = $this->orderablePi([
            ['supplier_id' => $alpha->id, 'supplier_price' => '6.5000'],
            ['supplier_id' => $beta->id, 'supplier_price' => '3.2000'],
        ]);
        [$a, $b] = $pi->lines->all();

        // The PI offers it, the board lists the PI as waiting, and "New" without a PI picks one (no 404).
        $create = route('supplier-orders.create', ['pi' => $pi->id]);
        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertOk()->assertSee($create, false)->assertSee('Make purchase orders');
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertOk()->assertSee($create, false)->assertSee('New purchase orders');
        $this->actingAs($manager)->get(route('supplier-orders.create'))->assertOk()->assertSee($pi->reference())->assertSee($create, false);

        $group = fn ($supplier, $line) => ['supplier_id' => $supplier->id, 'currency' => 'CNY', 'confirmation_date' => now()->toDateString(),
            'delivery_date' => now()->addDays(30)->toDateString(), 'lines' => [$line->id => ['include' => 1, 'unit_price' => '']]];
        $this->actingAs($manager)->post(route('supplier-orders.store'), ['pi' => $pi->id, 'groups' => [
            $alpha->id => $group($alpha, $a), $beta->id => $group($beta, $b),
        ]])->assertRedirect(route('supplier-orders.index'))->assertSessionHasNoErrors();

        $this->assertSame([$alpha->id, $beta->id], SupplierOrder::orderBy('number')->pluck('supplier_id')->all());
        $board = $this->actingAs($manager)->get(route('supplier-orders.index'))->assertOk();
        foreach (SupplierOrder::all() as $order) {
            $board->assertSee($order->number);
        }
        // Every line is ordered: the PI no longer offers it and no longer waits.
        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertDontSee($create, false);
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertDontSee($create, false);
    }

    public function test_an_issued_pi_waits_for_its_deposit_unless_the_business_orders_before_it(): void
    {
        $manager = $this->user(Roles::MANAGER)->forceFill(['locale' => 'en']);
        $supplier = $this->supplier();
        $pi = ProformaInvoice::factory()->issued()
            ->has(PiLine::factory()->state(['supplier_id' => $supplier->id, 'supplier_price' => '2', 'line_no' => 1]), 'lines')->create();
        $create = route('supplier-orders.create', ['pi' => $pi->id]);

        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertOk()->assertDontSee($create, false);
        $this->actingAs($manager)->get($create)->assertRedirect(route('proforma-invoices.show', $pi))
            ->assertSessionHas('error', 'Purchase orders are made from a confirmed PI: confirm the client\'s deposit first.');
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertSee('made from a confirmed PI')->assertDontSee('made from an issued PI');

        BusinessSetting::current()->fill(['orders_from_issued_pi' => true])->save();
        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertSee($create, false);
        $this->actingAs($manager)->get($create)->assertOk();
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertSee('made from an issued PI')->assertDontSee('made from a confirmed PI');
    }

    public function test_who_does_not_see_suppliers_is_not_offered_it(): void
    {
        $sales = $this->user(Roles::SALES)->forceFill(['locale' => 'en']);
        $pi = $this->orderablePi([['supplier_id' => $this->supplier()->id, 'supplier_price' => '1']]);
        $pi->client->forceFill(['account_manager_id' => $sales->id])->save();
        $this->actingAs($sales)->get(route('proforma-invoices.show', $pi))->assertOk()
            ->assertDontSee(route('supplier-orders.create', ['pi' => $pi->id]), false);
    }
}
