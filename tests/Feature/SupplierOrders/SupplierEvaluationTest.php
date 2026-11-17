<?php

namespace Tests\Feature\SupplierOrders;

use App\Security\Roles;
use App\Services\SupplierOrders\GoodsReceiptService;
use App\Services\SupplierOrders\OrderStepService;
use App\Services\SupplierOrders\SupplierEvaluation;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.3/§7: on-time against the FIRST promised date, slippage, date changes, inspection failures, sample reopenings. */
class SupplierEvaluationTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_evaluation_numbers_on_a_small_fixture(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $orders = app(SupplierOrderService::class);
        $receipts = app(GoodsReceiptService::class);
        $steps = app(OrderStepService::class);
        $day = fn (int $offset) => Carbon::today()->addDays($offset)->toDateString();
        $receiveAll = function ($order, int $offset, string $result = 'pass') use ($receipts, $merch, $day) {
            foreach ($order->lines as $line) {
                $receipts->receive($line, ['cartons' => $line->cartons, 'inspection_result' => $result, 'received_on' => $day($offset)], $merch);
            }
        };

        $alpha = $this->supplier(['name' => 'Alpha Factory']);
        $beta = $this->supplier(['name' => 'Beta Factory']);

        // Alpha 1: promised 20 days ago, all in 22 days ago → on time; its initial sample was reopened once.
        $a1 = $this->orderWithLines(2, 10, ['supplier_id' => $alpha->id, 'delivery_date' => $day(-20)]);
        $receiveAll($a1, -22);
        $steps->markDone($a1, $this->step('initial_sample'), $merch, $day(-40));
        $steps->reopen($a1, $this->step('initial_sample'), $merch, 'Wrong colour');
        $steps->markDone($a1, $this->step('initial_sample'), $merch, $day(-35));

        // Alpha 2: first promised 30 days ago, moved to 10 days ago, in 12 days ago (failed inspection) → late by 18.
        $a2 = $this->orderWithLines(1, 10, ['supplier_id' => $alpha->id, 'delivery_date' => $day(-30)]);
        $orders->changeDeliveryDate($a2, $day(-10), 'Power cuts', $merch);
        $receiveAll($a2->fresh(), -12, 'fail');

        // Alpha 3: due in 10 days, nothing in yet → not judged.
        $this->orderWithLines(1, 10, ['supplier_id' => $alpha->id, 'delivery_date' => $day(10)]);

        // Beta 1: promised 5 days ago, half in → still short and late by 5 so far.
        $b1 = $this->orderWithLines(1, 10, ['supplier_id' => $beta->id, 'delivery_date' => $day(-5)]);
        $receipts->receive($b1->lines->first(), ['cartons' => 5, 'inspection_result' => 'pass', 'received_on' => $day(-6)], $merch);
        // Beta 2: cancelled → not counted.
        $this->orderWithLines(1, 10, ['supplier_id' => $beta->id, 'delivery_date' => $day(-50), 'status' => 'cancelled']);

        $rows = app(SupplierEvaluation::class)->rows()->keyBy('supplier_id');

        $a = $rows[$alpha->id];
        $this->assertSame([3, 2, 1, 50.0, 9.0, 1, 1, 1], [
            $a->orders, $a->judged, $a->on_time, $a->on_time_percent, $a->avg_slippage_days, $a->date_changes, $a->inspection_failures, $a->sample_reopenings,
        ]);
        $b = $rows[$beta->id];
        $this->assertSame([1, 1, 0, 0.0, 5.0, 0, 0, 0], [
            $b->orders, $b->judged, $b->on_time, $b->on_time_percent, $b->avg_slippage_days, $b->date_changes, $b->inspection_failures, $b->sample_reopenings,
        ]);

        $this->actingAs($merch)->get(route('supplier-orders.evaluation'))->assertOk()
            ->assertSee('Alpha Factory')->assertSee('Beta Factory')->assertSee('50%')->assertSee('(1/2)', false);
    }

    public function test_a_line_fully_received_then_corrected_is_full_only_when_it_is_again(): void
    {
        $merch = $this->user(Roles::WAREHOUSE_QC);
        $receipts = app(GoodsReceiptService::class);
        $order = $this->orderWithLines(1, 10, ['delivery_date' => Carbon::today()->subDays(3)]);
        $line = $order->lines->first();

        $first = $receipts->receive($line, ['cartons' => 10, 'inspection_result' => 'pass', 'received_on' => Carbon::today()->subDays(5)->toDateString()], $merch);
        $receipts->correct($first, ['cartons' => 2, 'note' => 'Two belonged elsewhere', 'received_on' => Carbon::today()->subDays(4)->toDateString()], $merch);
        $receipts->receive($line, ['cartons' => 2, 'inspection_result' => 'pass', 'received_on' => Carbon::today()->subDay()->toDateString()], $merch);

        $row = app(SupplierEvaluation::class)->rows()->first();
        $this->assertSame(0, $row->on_time); // full again only yesterday, after the promise 3 days ago
        $this->assertSame(2.0, $row->avg_slippage_days);
    }
}
