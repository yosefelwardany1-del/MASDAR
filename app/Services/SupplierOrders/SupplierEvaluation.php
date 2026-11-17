<?php

namespace App\Services\SupplierOrders;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderDateChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier evaluation (spec §5.3, §7; S2's "management, observation, and evaluation"). Per
 * supplier, over its orders that are not cancelled (optionally those confirmed in a period):
 *
 *  - on-time %: an order is on time when it was fully received on or before the FIRST date the
 *    supplier promised (the first date change's old date, else the delivery date). Counted over
 *    the orders that can be judged: fully received, or still short with that date passed;
 *  - average slippage: days the goods came (or, still short, today) after that first promise,
 *    early counting as 0, over the same orders;
 *  - delivery-date changes, inspection failures (receipts that failed, not corrections) and
 *    sample reopenings (reopened events on the initial and final sample steps).
 *
 * Read-only. Everything is computed from the logs; nothing is stored.
 */
class SupplierEvaluation
{
    public const SAMPLE_STEPS = ['initial_sample', 'final_sample'];

    /**
     * @return Collection<int, object> one row per supplier with any order, most orders first:
     *                                 supplier_id, supplier_name, orders, judged, on_time, on_time_percent (?float),
     *                                 avg_slippage_days (?float), date_changes, inspection_failures, sample_reopenings
     */
    public function rows(?string $from = null, ?string $to = null): Collection
    {
        $orderQuery = SupplierOrder::query()
            ->where('status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereRaw('COALESCE(confirmation_date, created_at::date) >= CAST(? AS date)', [$from]))
            ->when($to, fn ($q) => $q->whereRaw('COALESCE(confirmation_date, created_at::date) <= CAST(? AS date)', [$to]));

        $orders = (clone $orderQuery)->with('supplier:id,name')->get(['id', 'supplier_id', 'delivery_date', 'status']);
        if ($orders->isEmpty()) {
            return collect();
        }
        $ids = (clone $orderQuery)->select('id');

        $changes = SupplierOrderDateChange::query()->whereIn('supplier_order_id', $ids)->orderBy('id')->get()->groupBy('supplier_order_id');
        $lines = DB::table('supplier_order_lines')->whereIn('supplier_order_id', $ids)->get(['id', 'supplier_order_id', 'cartons'])->groupBy('supplier_order_id');
        $receipts = DB::table('goods_receipts as g')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'g.supplier_order_line_id')
            ->whereIn('sol.supplier_order_id', $ids)
            ->orderBy('g.received_on')->orderBy('g.id')
            ->get(['g.supplier_order_line_id', 'sol.supplier_order_id', 'g.received_on', 'g.cartons', 'g.inspection_result', 'g.corrects_receipt_id']);
        $receiptsByLine = $receipts->groupBy('supplier_order_line_id');
        $failures = $receipts->filter(fn ($r) => $r->inspection_result === 'fail' && $r->corrects_receipt_id === null)->countBy('supplier_order_id');
        $reopenings = DB::table('supplier_order_step_events as e')
            ->join('order_step_definitions as d', 'd.id', '=', 'e.step_definition_id')
            ->whereIn('e.supplier_order_id', $ids)
            ->whereIn('d.code', self::SAMPLE_STEPS)
            ->where('e.status', 'reopened')
            ->groupBy('e.supplier_order_id')
            ->selectRaw('e.supplier_order_id, COUNT(*) AS n')
            ->pluck('n', 'supplier_order_id');

        $today = CarbonImmutable::today();
        $rows = [];
        foreach ($orders as $order) {
            $row = $rows[$order->supplier_id] ??= (object) [
                'supplier_id' => $order->supplier_id, 'supplier_name' => $order->supplier?->name, 'orders' => 0, 'judged' => 0,
                'on_time' => 0, 'slippage_total' => 0, 'date_changes' => 0, 'inspection_failures' => 0, 'sample_reopenings' => 0,
            ];
            $row->orders++;
            $row->date_changes += $changes->get($order->id)?->count() ?? 0;
            $row->inspection_failures += $failures->get($order->id, 0);
            $row->sample_reopenings += (int) $reopenings->get($order->id, 0);

            $promised = $order->firstPromisedDate($changes->get($order->id, collect()));
            if ($promised === null) {
                continue;
            }
            $promised = $promised->toImmutable()->startOfDay();
            $received = $this->fullyReceivedOn($lines->get($order->id, collect()), $receiptsByLine);
            if ($received === null && ! $promised->lessThan($today)) {
                continue; // still short, but not yet due: not judged yet
            }
            $row->judged++;
            if ($received !== null && $received->lessThanOrEqualTo($promised)) {
                $row->on_time++;
            }
            $actual = $received ?? $today;
            $row->slippage_total += $actual->greaterThan($promised) ? (int) $promised->diffInDays($actual) : 0;
        }

        return collect($rows)->map(function ($row) {
            $row->on_time_percent = $row->judged > 0 ? round(100 * $row->on_time / $row->judged, 1) : null;
            $row->avg_slippage_days = $row->judged > 0 ? round($row->slippage_total / $row->judged, 1) : null;
            unset($row->slippage_total);

            return $row;
        })->sortBy([['orders', 'desc'], ['supplier_name', 'asc']])->values();
    }

    /**
     * The day the order became (and stayed) fully received: for each line, the receipt after
     * which its running total of cartons never fell below the cartons ordered; the latest of
     * those. Null while any line is short, or the order has no lines.
     *
     * @param  Collection<int, object>  $lines
     * @param  Collection<int|string, Collection<int, object>>  $receiptsByLine
     */
    public function fullyReceivedOn(Collection $lines, Collection $receiptsByLine): ?CarbonImmutable
    {
        if ($lines->isEmpty()) {
            return null;
        }
        $latest = null;
        foreach ($lines as $line) {
            $running = '0';
            $fullSince = null;
            foreach ($receiptsByLine->get($line->id, collect()) as $receipt) {
                $running = bcadd($running, (string) $receipt->cartons, 4);
                if (bccomp($running, (string) $line->cartons, 4) >= 0) {
                    $fullSince ??= CarbonImmutable::parse($receipt->received_on)->startOfDay();
                } else {
                    $fullSince = null;
                }
            }
            if ($fullSince === null) {
                return null;
            }
            $latest = $latest === null || $fullSince->greaterThan($latest) ? $fullSince : $latest;
        }

        return $latest;
    }
}
