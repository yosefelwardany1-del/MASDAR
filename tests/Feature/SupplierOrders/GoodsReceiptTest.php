<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\SupplierOrderStepEvent;
use App\Security\Roles;
use App\Services\SupplierOrders\GoodsReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Step 8 of S2: cartons received per line, inspected; corrections are negative rows. */
class GoodsReceiptTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    private function receive(int $lineId, string|int $cartons, array $extra = [])
    {
        return $this->post(route('goods-receipts.store'), $extra + [
            'supplier_order_line_id' => $lineId, 'received_on' => Carbon::today()->toDateString(), 'cartons' => $cartons, 'inspection_result' => 'pass',
        ]);
    }

    public function test_a_complete_receipt_marks_receive_goods_done(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines(2, 10);
        [$first, $second] = $order->lines()->orderBy('id')->get()->all();
        $receiveGoods = $this->step('receive_goods');
        $this->actingAs($qc);

        $this->receive($first->id, 10, ['measured_cbm' => '0.8500', 'measured_kg' => '125', 'location' => 'Warehouse A', 'note' => 'Two cartons dented'])
            ->assertSessionHasNoErrors();
        $this->receive($second->id, 6)->assertSessionHasNoErrors();
        $this->assertFalse(SupplierOrderStepEvent::where('step_definition_id', $receiveGoods->id)->exists());

        $this->receive($second->id, 4, ['received_on' => Carbon::yesterday()->toDateString(), 'inspection_result' => 'pass_with_notes'])->assertSessionHasNoErrors();

        $event = SupplierOrderStepEvent::where('step_definition_id', $receiveGoods->id)->sole();
        $this->assertSame('done', $event->status);
        $this->assertSame($order->id, $event->supplier_order_id);
        $this->assertSame(Carbon::yesterday()->toDateString(), $event->happened_on->toDateString());
        $this->assertSame($qc->id, $event->user_id);

        $receipt = GoodsReceipt::orderBy('id')->first();
        $this->assertSame(['10.0000', '0.8500', '125.0000', 'Warehouse A'], [$receipt->cartons, $receipt->measured_cbm, $receipt->measured_kg, $receipt->location]);
        $this->assertSame(3, AuditLog::where('model_type', GoodsReceipt::class)->where('action', 'created')->count());

        // The line has all its cartons: it leaves the awaiting list.
        $this->get(route('goods-receipts.index'))->assertOk()->assertSee(__('No open order line is awaiting goods.'));
    }

    public function test_receipts_are_refused_when_they_make_no_sense(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines();
        $line = $order->lines()->first();
        $this->actingAs($qc);

        $this->receive($line->id, 0)->assertSessionHasErrors('cartons');
        $this->receive($line->id, -2)->assertSessionHasErrors('cartons');
        $this->receive($line->id, 2, ['inspection_result' => 'maybe'])->assertSessionHasErrors('inspection_result');
        $this->receive($line->id, 2, ['received_on' => Carbon::tomorrow()->toDateString()])->assertSessionHasErrors('received_on');
        $order->update(['status' => 'completed']);
        $this->receive($line->id, 2)->assertSessionHasErrors('cartons');
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_a_correction_below_zero_is_refused_politely(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines();
        $line = $order->lines()->first();
        $this->actingAs($qc);
        $this->receive($line->id, 4)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::sole();

        $this->post(route('goods-receipts.correct', $receipt), ['cartons' => 5, 'note' => 'Counted twice'])
            ->assertRedirect()->assertSessionHasErrors('cartons');
        $this->assertStringContainsString('4', session('errors')->first('cartons'));
        $this->assertSame(1, GoodsReceipt::count());

        $this->post(route('goods-receipts.correct', $receipt), ['cartons' => 1, 'note' => ''])->assertSessionHasErrors('note');

        $this->post(route('goods-receipts.correct', $receipt), ['cartons' => 1, 'note' => 'One carton belonged to another order'])->assertSessionHasNoErrors();
        $correction = GoodsReceipt::where('corrects_receipt_id', $receipt->id)->sole();
        $this->assertSame('-1.0000', $correction->cartons);
        $this->assertSame('3.0000', (string) DB::table('goods_receipts')->where('supplier_order_line_id', $line->id)->sum('cartons'));
        $this->assertTrue(AuditLog::where('model_type', GoodsReceipt::class)->where('action', 'corrected')->exists());

        // What is left of the receipt (3) is the most a further correction may take off.
        try {
            app(GoodsReceiptService::class)->correct($receipt, ['cartons' => '3.5', 'note' => 'x'], $qc);
            $this->fail('the correction went below zero');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('3', $e->errors()['cartons'][0]);
        }
        $this->assertSame(2, GoodsReceipt::count());
    }

    public function test_a_correction_never_takes_received_below_loaded(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines();
        $line = $order->lines()->first();
        $this->actingAs($qc);
        $this->receive($line->id, 6)->assertSessionHasNoErrors();
        DB::table('container_loads')->insert([
            'container_id' => Container::factory()->create()->id, 'supplier_order_line_id' => $line->id, 'cartons' => 4, 'loaded_on' => now()->toDateString(), 'user_id' => $qc->id,
        ]);

        $this->post(route('goods-receipts.correct', GoodsReceipt::sole()), ['cartons' => 3, 'note' => 'Short'])->assertSessionHasErrors('cartons');
        $this->assertStringContainsString('2', session('errors')->first('cartons'));
        $this->post(route('goods-receipts.correct', GoodsReceipt::sole()), ['cartons' => 2, 'note' => 'Short'])->assertSessionHasNoErrors();
        $this->assertSame(2, GoodsReceipt::count());
    }

    public function test_the_receipt_screen_lists_lines_awaiting_goods_with_filters(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $mine = $this->orderWithLines(1, 10);
        $other = $this->orderWithLines(1, 10);
        $closed = $this->orderWithLines(1, 10, ['status' => 'completed']);
        $lineInput = fn ($order) => 'name="supplier_order_line_id" value="'.$order->lines()->first()->id.'"';

        $this->actingAs($qc)->get(route('goods-receipts.index'))->assertOk()
            ->assertSee($mine->number)->assertSee($other->number)->assertDontSee($closed->number)
            ->assertSee('name="supplier_order_line_id"', false);
        $this->actingAs($qc)->get(route('goods-receipts.index', ['order' => $mine->id]))->assertOk()
            ->assertSee($lineInput($mine), false)->assertDontSee($lineInput($other), false);
        $this->actingAs($qc)->get(route('goods-receipts.index', ['supplier' => $other->supplier_id]))->assertOk()
            ->assertSee($lineInput($other), false)->assertDontSee($lineInput($mine), false);

        // Viewing without recording: logistics sees the list but no form.
        $this->actingAs($this->user(Roles::LOGISTICS))->get(route('goods-receipts.index'))->assertOk()
            ->assertSee($mine->number)->assertDontSee('name="supplier_order_line_id"', false);
        $this->actingAs($this->user(Roles::LOGISTICS))->post(route('goods-receipts.store'), [])->assertForbidden();
    }
}
