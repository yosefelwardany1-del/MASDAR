<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\OrderStepDefinition;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;

/** Fixtures for module 3's tests: users by role, confirmed PIs with planned suppliers, orders. */
trait BuildsSupplierOrders
{
    protected function user(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    protected function step(string $code): OrderStepDefinition
    {
        return OrderStepDefinition::where('code', $code)->firstOrFail();
    }

    /**
     * A confirmed PI — the sales order purchase orders are made from (B01): lines added while it is a
     * draft, then issued and confirmed, as the service does.
     *
     * @param  list<array<string, mixed>>  $lines  PiLine attributes per line
     */
    protected function orderablePi(array $lines, array $attributes = []): ProformaInvoice
    {
        $sequence = array_map(fn ($line, $i) => $line + ['line_no' => $i + 1], $lines, array_keys($lines));

        return ProformaInvoice::factory()->confirmed()
            ->has(PiLine::factory()->count(count($lines))->sequence(...$sequence), 'lines')
            ->create($attributes);
    }

    /** An open order with $lines lines of $cartons cartons each. */
    protected function orderWithLines(int $lines = 1, int $cartons = 10, array $attributes = []): SupplierOrder
    {
        $order = SupplierOrder::factory()->create($attributes);
        SupplierOrderLine::factory()->count($lines)->create(['supplier_order_id' => $order->id, 'cartons' => $cartons]);

        return $order;
    }

    protected function supplier(array $attributes = []): Party
    {
        return Party::factory()->supplier()->create($attributes);
    }
}
