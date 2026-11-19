<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\AuditLog;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.3: a PI bought from several suppliers gives one supplier order per supplier. */
class CreateFromPiTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_a_confirmed_pi_with_two_planned_suppliers_gives_two_numbered_orders(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        [$alpha, $beta] = [$this->supplier(['name' => 'Alpha Toys']), $this->supplier(['name' => 'Beta Bags'])];
        $pi = $this->orderablePi([
            ['supplier_id' => $alpha->id, 'supplier_price' => '6.5000', 'cartons' => 10, 'qty_per_carton' => 48],
            ['supplier_id' => $beta->id, 'supplier_price' => '3.2000', 'cartons' => 5, 'qty_per_carton' => 100],
            ['supplier_id' => $alpha->id, 'supplier_price' => '2.0000', 'cartons' => 8, 'qty_per_carton' => 12],
        ]);
        [$a1, $b1, $a2] = $pi->lines->all();

        $this->actingAs($merch)->get(route('supplier-orders.create', ['pi' => $pi->id]))->assertOk()
            ->assertSee('Alpha Toys')->assertSee('Beta Bags')
            ->assertSee('groups['.$alpha->id.'][lines]['.$a1->id.'][unit_price]', false)
            ->assertSee('groups['.$beta->id.'][lines]['.$b1->id.'][unit_price]', false);

        $group = fn ($supplier, array $lines) => [
            'supplier_id' => $supplier->id, 'currency' => 'CNY', 'confirmation_date' => '2026-11-10', 'delivery_date' => '2026-12-20',
            'deposit_percent' => '30', 'payment_terms' => '30% deposit, 70% before loading', 'merchandiser_id' => $merch->id, 'lines' => $lines,
        ];
        $this->actingAs($merch)->post(route('supplier-orders.store'), ['pi' => $pi->id, 'groups' => [
            $alpha->id => $group($alpha, [$a1->id => ['include' => 1, 'unit_price' => '6.4000'], $a2->id => ['include' => 1, 'unit_price' => '']]),
            $beta->id => $group($beta, [$b1->id => ['include' => 1, 'unit_price' => '3.2000']]),
        ]])->assertRedirect(route('supplier-orders.index'))->assertSessionHasNoErrors();

        $year = now()->format('Y');
        $orders = SupplierOrder::with('lines')->orderBy('number')->get();
        $this->assertSame(["PO-{$year}-0001", "PO-{$year}-0002"], $orders->pluck('number')->all());

        [$first, $second] = $orders->all();
        $this->assertSame($alpha->id, $first->supplier_id); // numbered in the PI's line order
        $this->assertSame($beta->id, $second->supplier_id);
        $this->assertSame('CNY', $first->currency);
        $this->assertSame('2026-12-20', $first->delivery_date->toDateString());
        $this->assertSame($merch->id, $first->merchandiser_id);
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $first->lines->pluck('pi_line_id')->all());
        $this->assertSame([$b1->id], $second->lines->pluck('pi_line_id')->all());

        $byPiLine = SupplierOrderLine::all()->keyBy('pi_line_id');
        $this->assertSame('6.4000', $byPiLine[$a1->id]->unit_price);  // as entered
        $this->assertSame('2.0000', $byPiLine[$a2->id]->unit_price);  // the PI line's supplier price
        $this->assertSame('3.2000', $byPiLine[$b1->id]->unit_price);
        $this->assertSame('10.0000', $byPiLine[$a1->id]->cartons);
        $this->assertSame('48.0000', $byPiLine[$a1->id]->qty_per_carton);
        $this->assertSame('3072.0000', $byPiLine[$a1->id]->fresh()->amount); // 10 × 48 × 6.40, by the database

        $this->assertSame(2, AuditLog::where('model_type', SupplierOrder::class)->where('action', 'created')->count());
    }

    public function test_groups_given_the_same_supplier_become_one_order(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $chosen = $this->supplier();
        $pi = $this->orderablePi([['supplier_id' => $this->supplier()->id, 'supplier_price' => '1'], ['supplier_id' => null, 'supplier_price' => '2']]);
        [$l1, $l2] = $pi->lines->all();

        $orders = app(SupplierOrderService::class)->createFromPi($pi, [
            ['supplier_id' => $chosen->id, 'currency' => 'USD', 'lines' => [$l1->id => null]],
            ['supplier_id' => $chosen->id, 'currency' => 'CNY', 'lines' => [$l2->id => '2.5']],
        ], $merch);

        $this->assertCount(1, $orders);
        $this->assertSame('USD', $orders->first()->currency); // the first group's header
        $this->assertSame(2, $orders->first()->lines()->count());
    }

    public function test_lines_already_on_an_order_are_not_offered_again(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $supplier = $this->supplier();
        $pi = $this->orderablePi([['supplier_id' => $supplier->id, 'supplier_price' => '1'], ['supplier_id' => $supplier->id, 'supplier_price' => '2']]);
        [$l1, $l2] = $pi->lines->all();
        $service = app(SupplierOrderService::class);
        $service->createFromPi($pi, [['supplier_id' => $supplier->id, 'lines' => [$l1->id => null]]], $merch);

        $this->assertSame([$l2->id], $service->unassignedLines($pi)->pluck('id')->all());
        $this->actingAs($merch)->get(route('supplier-orders.create', ['pi' => $pi->id]))->assertOk()
            ->assertDontSee('[lines]['.$l1->id.']', false)->assertSee('[lines]['.$l2->id.']', false);

        try {
            $service->createFromPi($pi, [['supplier_id' => $supplier->id, 'lines' => [$l1->id => '1']]], $merch);
            $this->fail('a line already on an order was ordered again');
        } catch (ValidationException) {
            $this->assertSame(1, SupplierOrder::count());
        }

        $service->createFromPi($pi, [['supplier_id' => $supplier->id, 'lines' => [$l2->id => null]]], $merch);
        $this->actingAs($merch)->get(route('supplier-orders.create', ['pi' => $pi->id]))->assertOk()
            ->assertSee(__('Every line of this PI is already on a supplier order.'));
    }

    public function test_a_line_ordered_from_an_older_revision_is_not_offered_from_the_new_one(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $supplier = $this->supplier();
        $rev1 = $this->orderablePi([['supplier_id' => $supplier->id, 'supplier_price' => '1']]);
        $ordered = $rev1->lines->first();
        app(SupplierOrderService::class)->createFromPi($rev1, [['supplier_id' => $supplier->id, 'lines' => [$ordered->id => null]]], $merch);

        // The PI is revised: rev 1 is superseded, rev 2 copies its line and adds one.
        $rev1->update(['status' => 'superseded', 'is_current' => false]);
        $rev2 = ProformaInvoice::factory()->create(['pi_number' => $rev1->pi_number, 'revision' => 2, 'client_id' => $rev1->client_id]);
        $copy = PiLine::factory()->create(['proforma_invoice_id' => $rev2->id, 'line_no' => 1, 'source_line_id' => $ordered->id, 'supplier_id' => $supplier->id]);
        $new = PiLine::factory()->create(['proforma_invoice_id' => $rev2->id, 'line_no' => 2, 'supplier_id' => $supplier->id, 'supplier_price' => '4']);
        $rev2->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $rev2->created_by]);

        $this->assertSame([$new->id], app(SupplierOrderService::class)->unassignedLines($rev2)->pluck('id')->all());

        // The database refuses it too, whoever writes.
        DB::beginTransaction();
        try {
            SupplierOrderLine::factory()->create(['pi_line_id' => $copy->id]);
            $this->fail('the database accepted another revision of an ordered line');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_draft_pi_cannot_be_ordered_from(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $pi = ProformaInvoice::factory()->create();
        $line = PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'supplier_price' => '1']);

        $this->actingAs($merch)->get(route('supplier-orders.create', ['pi' => $pi->id]))->assertRedirect(route('proforma-invoices.show', $pi));
        $this->expectException(ValidationException::class);
        app(SupplierOrderService::class)->createFromPi($pi, [['supplier_id' => $this->supplier()->id, 'lines' => [$line->id => null]]], $merch);
    }

    public function test_a_group_without_a_supplier_or_a_price_is_refused(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $pi = $this->orderablePi([['supplier_id' => null, 'supplier_price' => null]]);
        $line = $pi->lines->first();

        $this->actingAs($merch)->post(route('supplier-orders.store'), ['pi' => $pi->id, 'groups' => [
            0 => ['supplier_id' => '', 'currency' => 'CNY', 'lines' => [$line->id => ['include' => 1, 'unit_price' => '1']]],
        ]])->assertSessionHasErrors('groups');

        $this->actingAs($merch)->post(route('supplier-orders.store'), ['pi' => $pi->id, 'groups' => [
            0 => ['supplier_id' => $this->supplier()->id, 'currency' => 'CNY', 'lines' => [$line->id => ['include' => 1, 'unit_price' => '']]],
        ]])->assertSessionHasErrors('lines');

        $this->assertSame(0, SupplierOrder::count());
    }

    public function test_lines_are_added_to_an_open_order(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $supplier = $this->supplier();
        $pi = $this->orderablePi([['supplier_id' => $supplier->id, 'supplier_price' => '1'], ['supplier_id' => $supplier->id, 'supplier_price' => '5']]);
        [$l1, $l2] = $pi->lines->all();
        $order = app(SupplierOrderService::class)->createFromPi($pi, [['supplier_id' => $supplier->id, 'lines' => [$l1->id => null]]], $merch)->first();

        $this->actingAs($merch)->get(route('supplier-orders.lines.create', [$order, 'pi' => $pi->id]))->assertOk()->assertSee('lines['.$l2->id.'][unit_price]', false);
        $this->actingAs($merch)->post(route('supplier-orders.lines.store', $order), ['pi' => $pi->id, 'lines' => [$l2->id => ['include' => 1, 'unit_price' => '4.5']]])
            ->assertRedirect(route('supplier-orders.show', $order))->assertSessionHasNoErrors();

        $this->assertSame('4.5000', $order->lines()->where('pi_line_id', $l2->id)->value('unit_price'));
        $this->assertTrue(AuditLog::where('model_type', SupplierOrder::class)->where('model_id', $order->id)->where('action', 'lines_added')->exists());
    }
}
