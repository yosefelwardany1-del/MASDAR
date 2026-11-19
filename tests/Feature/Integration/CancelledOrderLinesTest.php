<?php

namespace Tests\Feature\Integration;

use App\Models\GoodsReceipt;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * When a factory order is cancelled its PI lines can be ordered from another factory; the
 * cancelled order keeps them in its history, and reopening it takes them back only if nobody
 * ordered them elsewhere meanwhile.
 */
class CancelledOrderLinesTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $this->pi = ProformaInvoice::factory()->create();
        PiLine::factory()->create(['proforma_invoice_id' => $this->pi->id, 'line_no' => 1]);
        $this->pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $this->manager->id]);
        $this->pi->update(['status' => 'confirmed', 'confirmed_at' => now()]);
    }

    private function orderFrom(Party $factory)
    {
        return app(SupplierOrderService::class)->createFromPi($this->pi->fresh(), [[
            'supplier_id' => $factory->id,
            'lines' => $this->pi->lines()->pluck('id')->mapWithKeys(fn ($id) => [$id => '6.50'])->all(),
            'confirmation_date' => now()->toDateString(), 'delivery_date' => now()->addDays(20)->toDateString(), 'currency' => 'CNY',
        ]], $this->manager)->first();
    }

    public function test_a_cancelled_orders_lines_can_be_ordered_from_another_factory(): void
    {
        $orders = app(SupplierOrderService::class);
        $first = $this->orderFrom(Party::factory()->supplier()->create());
        $this->assertCount(0, $orders->unassignedLines($this->pi));

        $orders->cancel($first, $this->manager, 'Factory could not meet the price');
        $this->assertCount(1, $orders->unassignedLines($this->pi), 'the line is free again');
        $this->assertNotNull($first->lines()->first()->released_at, 'the cancelled order keeps the line, released');

        $second = $this->orderFrom(Party::factory()->supplier()->create());
        $this->assertSame($first->lines()->first()->pi_line_id, $second->lines()->first()->pi_line_id);

        try {
            $orders->reopen($first->fresh(), $this->manager);
            $this->fail('reopening must be refused: the line is ordered elsewhere');
        } catch (ValidationException $e) {
            $this->assertSame('cancelled', $first->fresh()->status, 'nothing changed');
        }
    }

    public function test_reopening_takes_the_lines_back_when_still_free(): void
    {
        $orders = app(SupplierOrderService::class);
        $order = $this->orderFrom(Party::factory()->supplier()->create());
        $orders->cancel($order, $this->manager, 'Client paused the order');

        $orders->reopen($order->fresh(), $this->manager);

        $this->assertSame('open', $order->fresh()->status);
        $this->assertNull($order->lines()->first()->released_at);
        $this->assertCount(0, $orders->unassignedLines($this->pi));
    }

    public function test_the_database_releases_only_lines_of_a_cancelled_order_without_goods(): void
    {
        $order = $this->orderFrom(Party::factory()->supplier()->create());
        $line = $order->lines()->first();

        DB::beginTransaction();
        try {
            DB::table('supplier_order_lines')->where('id', $line->id)->update(['released_at' => now()]);
            $this->fail('an open order\'s line was released');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => 1, 'inspection_result' => 'pass', 'user_id' => $this->manager->id]);
        DB::table('supplier_orders')->where('id', $order->id)->update(['status' => 'cancelled']);
        $this->expectException(QueryException::class);
        DB::table('supplier_order_lines')->where('id', $line->id)->update(['released_at' => now()]);
    }
}
