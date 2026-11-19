<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\AuditLog;
use App\Models\ErpNotification;
use App\Models\GoodsReceipt;
use App\Models\Party;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderDateChange;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The header, the promised delivery date (never overwritten silently) and the status. */
class SupplierOrderHeaderTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_a_delivery_date_change_is_logged_with_the_old_and_new_dates(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $follower = $this->user(Roles::MERCHANDISER);
        $sales = $this->user(Roles::SALES);
        $order = $this->orderWithLines(0, 10, ['delivery_date' => '2026-12-01', 'merchandiser_id' => $follower->id]);
        $pi = $this->orderablePi([['supplier_price' => '1']], ['client_id' => Party::factory()->client()->create(['account_manager_id' => $sales->id])->id]);
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'pi_line_id' => $pi->lines->first()->id]);

        $this->actingAs($merch)->post(route('supplier-orders.delivery-date', $order), ['delivery_date' => '2026-12-15', 'reason' => 'Fabric arrived late'])
            ->assertRedirect(route('supplier-orders.show', $order))->assertSessionHasNoErrors();

        $change = SupplierOrderDateChange::sole();
        $this->assertSame('2026-12-01', $change->old_date->toDateString());
        $this->assertSame('2026-12-15', $change->new_date->toDateString());
        $this->assertSame('Fabric arrived late', $change->reason);
        $this->assertSame($merch->id, $change->user_id);
        $this->assertSame('2026-12-15', $order->fresh()->delivery_date->toDateString());
        $this->assertSame('2026-12-01', $order->fresh()->firstPromisedDate()->toDateString());
        $this->assertTrue(AuditLog::where('model_type', SupplierOrder::class)->where('action', 'delivery_date_changed')->exists());

        // Spec §7 "Delivery date moved": the follower and the client's account manager, not the one who moved it.
        $this->assertTrue(ErpNotification::where('user_id', $follower->id)->exists());
        $this->assertTrue(ErpNotification::where('user_id', $sales->id)->exists());
        $this->assertFalse(ErpNotification::where('user_id', $merch->id)->exists());

        // A second move keeps the first promise.
        $this->actingAs($merch)->post(route('supplier-orders.delivery-date', $order), ['delivery_date' => '2026-12-20', 'reason' => 'Again']);
        $this->assertSame(2, SupplierOrderDateChange::count());
        $this->assertSame('2026-12-01', $order->fresh()->firstPromisedDate()->toDateString());

        // The same date again, or no reason, is refused.
        $this->actingAs($merch)->post(route('supplier-orders.delivery-date', $order), ['delivery_date' => '2026-12-20', 'reason' => 'Same'])->assertSessionHasErrors('delivery_date');
        $this->actingAs($merch)->post(route('supplier-orders.delivery-date', $order), ['delivery_date' => '2026-12-22', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(2, SupplierOrderDateChange::count());
    }

    public function test_the_header_is_edited_and_audited(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();

        $this->actingAs($merch)->put(route('supplier-orders.update', $order), [
            'confirmation_date' => '2026-11-01', 'currency' => 'USD', 'deposit_percent' => '25', 'payment_terms' => 'T/T', 'notes' => 'Rush',
        ])->assertRedirect(route('supplier-orders.show', $order))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['USD', '25.00', 'T/T', 'Rush', '2026-11-01'], [$order->currency, $order->deposit_percent, $order->payment_terms, $order->notes, $order->confirmation_date->toDateString()]);
        $this->assertTrue(AuditLog::where('model_type', SupplierOrder::class)->where('action', 'updated')->exists());

        $this->actingAs($merch)->put(route('supplier-orders.update', $order), ['currency' => 'XXX'])->assertSessionHasErrors('currency');
    }

    public function test_the_merchandiser_is_assigned(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $other = $this->user(Roles::MERCHANDISER);
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines();

        $this->actingAs($merch)->put(route('supplier-orders.merchandiser', $order), ['merchandiser_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $order->fresh()->merchandiser_id);

        $this->actingAs($merch)->put(route('supplier-orders.merchandiser', $order), ['merchandiser_id' => $qc->id])->assertSessionHasErrors('merchandiser_id');
        $this->assertSame($other->id, $order->fresh()->merchandiser_id);
    }

    public function test_status_moves_open_to_completed_or_cancelled_and_back(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();

        $this->actingAs($merch)->post(route('supplier-orders.complete', $order))->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->status);
        $this->actingAs($merch)->post(route('supplier-orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors('status');
        $this->actingAs($merch)->put(route('supplier-orders.update', $order), ['currency' => 'USD'])->assertSessionHasErrors('status');

        $this->actingAs($merch)->post(route('supplier-orders.reopen', $order), ['reason' => 'More cartons'])->assertSessionHasNoErrors();
        $this->assertSame('open', $order->fresh()->status);

        $this->actingAs($merch)->post(route('supplier-orders.cancel', $order), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($merch)->post(route('supplier-orders.cancel', $order), ['reason' => 'Client withdrew'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('Client withdrew', AuditLog::where('model_type', SupplierOrder::class)->where('action', 'cancelled')->sole()->new_values['reason']);

        $this->actingAs($merch)->get(route('supplier-orders.show', $order))->assertOk()->assertSee('Client withdrew');
    }

    public function test_an_order_with_goods_received_is_not_cancelled(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();
        GoodsReceipt::create(['supplier_order_line_id' => $order->lines()->first()->id, 'received_on' => Carbon::today(), 'cartons' => 2, 'inspection_result' => 'pass', 'user_id' => $merch->id]);

        $this->actingAs($merch)->post(route('supplier-orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'cancelled')->count());
    }
}
