<?php

namespace App\Services\SupplierOrders;

use App\Models\SupplierOrder;
use App\Models\SupplierOrderDateChange;
use App\Models\User;
use App\Support\Access\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The order follow-up board (spec §5.3, S2 as a screen): one row per supplier order, one column
 * per active step. Read-only. Late rows first; "late" is computed in SQL with the same rule as
 * OrderStepService (an open order with a step past due whose latest event is neither done nor
 * skipped), so it can filter and sort before paging. The page's step events, clients and
 * date changes are each one query.
 */
class FollowUpBoard
{
    public const STATUSES = ['open', 'completed', 'cancelled', 'all'];

    public function __construct(private OrderStepService $steps) {}

    /** SQL true when an order has a late step; one binding: today. */
    public static function lateSql(string $orders = 'supplier_orders'): string
    {
        return <<<SQL
            ({$orders}.status = 'open' AND {$orders}.delivery_date IS NOT NULL AND EXISTS (
                SELECT 1 FROM order_step_definitions d
                WHERE d.active AND d.due_offset_days IS NOT NULL
                  AND {$orders}.delivery_date - d.due_offset_days < CAST(? AS date)
                  AND COALESCE((
                      SELECT e.status FROM supplier_order_step_events e
                      WHERE e.supplier_order_id = {$orders}.id AND e.step_definition_id = d.id
                      ORDER BY e.id DESC LIMIT 1
                  ), 'reopened') = 'reopened'
            ))
        SQL;
    }

    /**
     * The orders $viewer may see under $filters: status (open by default), supplier, client,
     * merchandiser, late (only late rows), search (order number).
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, User $viewer): Builder
    {
        $today = CarbonImmutable::today()->toDateString();
        $status = in_array($filters['status'] ?? null, self::STATUSES, true) ? $filters['status'] : 'open';
        $search = trim((string) ($filters['search'] ?? ''));

        return SupplierOrder::query()
            ->select('supplier_orders.*')
            ->selectRaw(self::lateSql().' AS is_late', [$today])
            ->when($status !== 'all', fn ($q) => $q->where('supplier_orders.status', $status))
            ->when(! empty($filters['supplier']), fn ($q) => $q->where('supplier_orders.supplier_id', (int) $filters['supplier']))
            ->when(! empty($filters['merchandiser']), fn ($q) => $q->where('supplier_orders.merchandiser_id', (int) $filters['merchandiser']))
            ->when(! empty($filters['client']), fn ($q) => $q->whereExists(fn ($e) => $this->linesOfClients($e)->where('pi.client_id', (int) $filters['client'])))
            ->when(! empty($filters['late']), fn ($q) => $q->whereRaw(self::lateSql(), [$today]))
            ->when($search !== '', fn ($q) => $q->where('supplier_orders.number', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->when(Visibility::ownClientsOnly($viewer), fn ($q) => $q->whereExists(fn ($e) => $this->linesOfClients($e)->where('c.account_manager_id', $viewer->id)));
    }

    /** Whether $viewer may open $order: a sales user limited to own clients needs one of their lines on it. */
    public function visibleTo(SupplierOrder $order, User $viewer): bool
    {
        return ! Visibility::ownClientsOnly($viewer)
            || DB::table('supplier_order_lines as sol')
                ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
                ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
                ->join('parties as c', 'c.id', '=', 'pi.client_id')
                ->where('sol.supplier_order_id', $order->id)
                ->where('c.account_manager_id', $viewer->id)
                ->exists();
    }

    /**
     * One page of the board.
     *
     * @param  array<string, mixed>  $filters
     * @return array{orders: LengthAwarePaginator, steps: Collection, states: array, orderClients: array, original: array}
     */
    public function page(array $filters, User $viewer, int $perPage = 50): array
    {
        $orders = $this->query($filters, $viewer)
            ->with(['supplier:id,code,name', 'merchandiser:id,name'])
            ->orderByRaw('is_late DESC')
            ->orderByRaw('supplier_orders.delivery_date ASC NULLS LAST')
            ->orderByDesc('supplier_orders.id')
            ->paginate($perPage)
            ->withQueryString();

        $page = collect($orders->items());
        $steps = $this->steps->activeSteps();

        return [
            'orders' => $orders,
            'steps' => $steps,
            'states' => $this->steps->boardStates($page, $steps),
            'orderClients' => $this->clientsOf($page->pluck('id'), $viewer),
            'original' => $this->originalDates($page),
        ];
    }

    /**
     * Each order's clients and PI numbers, as order id => list of {client_id, client_name, pi_id, pi_reference}.
     *
     * @param  Collection<int, int>  $orderIds
     */
    public function clientsOf(Collection $orderIds, User $viewer): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        return DB::table('supplier_order_lines as sol')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->whereIn('sol.supplier_order_id', $orderIds)
            ->when(Visibility::ownClientsOnly($viewer), fn ($q) => $q->where('c.account_manager_id', $viewer->id))
            ->distinct()
            ->orderBy('c.name')->orderBy('pi.pi_number')->orderBy('pi.revision')
            ->get(['sol.supplier_order_id', 'c.id as client_id', 'c.name as client_name', 'pi.id as pi_id', 'pi.pi_number', 'pi.revision'])
            ->map(function ($row) {
                $row->pi_reference = $row->revision > 1 ? "{$row->pi_number} rev {$row->revision}" : $row->pi_number;

                return $row;
            })
            ->groupBy('supplier_order_id')
            ->map(fn (Collection $rows) => $rows->values()->all())
            ->all();
    }

    /**
     * The first promised delivery date and the number of changes, for each order whose date moved.
     *
     * @param  Collection<int, SupplierOrder>  $orders
     * @return array<int, array{date: ?Carbon, changes: int}>
     */
    private function originalDates(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $changes = SupplierOrderDateChange::query()->whereIn('supplier_order_id', $orders->pluck('id'))->orderBy('id')->get()->groupBy('supplier_order_id');

        $moved = [];
        foreach ($orders as $order) {
            $own = $changes->get($order->id);
            if (! $own) {
                continue;
            }
            $first = $order->firstPromisedDate($own);
            if ($first?->toDateString() !== $order->delivery_date?->toDateString()) {
                $moved[$order->id] = ['date' => $first, 'changes' => $own->count()];
            }
        }

        return $moved;
    }

    /** A correlated subquery over the order's lines, their PIs (pi) and clients (c). */
    private function linesOfClients($query)
    {
        return $query->selectRaw('1')->from('supplier_order_lines as sol')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->whereColumn('sol.supplier_order_id', 'supplier_orders.id');
    }
}
