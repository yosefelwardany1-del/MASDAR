<?php

namespace App\Services\Shipping;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Claims\NoteQueries;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries of the container follow-up (spec §5.5): the board, what is inside a
 * container, what can still be loaded, and whether a PI is paid. Writes nothing. Every
 * quantity is summed in SQL (numeric), never in PHP floats.
 */
class ShippingQueries
{
    /** The latest event of a container, as SQL (I8: the latest event is the status). */
    private const LAST_STATUS = '(SELECT e.status FROM container_events e WHERE e.container_id = containers.id ORDER BY e.id DESC LIMIT 1)';

    private const LAST_SORT = "(SELECT l.sort FROM container_events e JOIN lookups l ON l.list = 'container_status' AND l.code = e.status
        WHERE e.container_id = containers.id ORDER BY e.id DESC LIMIT 1)";

    /** Net cartons × the PI line's carton spec, per container. */
    private const LOADED = '(SELECT COALESCE(ROUND(SUM(cl.cartons * pl.%1$s_per_carton), 4), 0) FROM container_loads cl
        JOIN supplier_order_lines sol ON sol.id = cl.supplier_order_line_id
        JOIN pi_lines pl ON pl.id = sol.pi_line_id WHERE cl.container_id = containers.id)';

    /** The container type's capacity from its list entry (meta.cbm / meta.max_kg), or NULL. */
    private const CAPACITY = "(SELECT CASE WHEN lt.meta->>'%1\$s' ~ '^[0-9]+(\\.[0-9]+)?$' THEN NULLIF((lt.meta->>'%1\$s')::numeric, 0) END
        FROM lookups lt WHERE lt.list = 'container_type' AND lt.code = containers.container_type)";

    /** The client ids $user is narrowed to (Visibility::ownClientsOnly), or null for all. */
    public function visibleClientIds(User $user): ?array
    {
        if (! Visibility::ownClientsOnly($user)) {
            return null;
        }

        return Visibility::scopeClients(Party::query()->clients(), $user)->pluck('id')->all();
    }

    /**
     * The sort from which a container counts as arrived: the `arrived` status and every one
     * after it (released, delivered …) along the container_status list. Without an `arrived`
     * entry nothing counts as arrived, so late containers keep showing (as on the dashboard).
     */
    public function arrivedSort(): int
    {
        return (int) (Lookup::query()->where('list', 'container_status')->where('code', 'arrived')->value('sort') ?? 2147483647);
    }

    /** code => name in the user's language for every entry of $list, active or not. */
    public function names(string $list): array
    {
        return Lookup::query()->ofList($list)->get()->mapWithKeys(fn (Lookup $l) => [$l->code => $l->displayName()])->all();
    }

    /** The active container statuses, in path order: code => name. */
    public function statusOptions(): array
    {
        return Lookup::options('container_status');
    }

    /** Narrow a containers query to those holding goods (net of unloads) of $clientIds. */
    public function scopeHoldingClients(Builder|QueryBuilder $query, array $clientIds): Builder|QueryBuilder
    {
        return $query->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
            ->from('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->whereColumn('cl.container_id', 'containers.id')
            ->whereIn('pi.client_id', $clientIds)
            ->groupBy('cl.supplier_order_line_id')
            ->havingRaw('SUM(cl.cartons) > 0'));
    }

    /** Narrow a shipments query to those carrying (booked or loaded) goods of $clientIds. */
    public function scopeShipmentsForClients(Builder $query, array $clientIds): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')
                ->from('shipment_orders as sb')
                ->join('supplier_order_lines as sol', 'sol.supplier_order_id', '=', 'sb.supplier_order_id')
                ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
                ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
                ->whereColumn('sb.shipment_id', 'shipments.id')
                ->whereIn('pi.client_id', $clientIds))
            ->orWhereExists(fn (QueryBuilder $s) => $this->scopeHoldingClients(
                $s->selectRaw('1')->from('containers')->whereColumn('containers.shipment_id', 'shipments.id'),
                $clientIds,
            )));
    }

    /** Whether $user may open $container: always, unless narrowed to own clients and none of theirs is inside. */
    public function canSeeContainer(User $user, Container $container): bool
    {
        $clientIds = $this->visibleClientIds($user);

        return $clientIds === null || $this->scopeHoldingClients(Container::query()->whereKey($container->getKey()), $clientIds)->exists();
    }

    public function canSeeShipment(User $user, Shipment $shipment): bool
    {
        $clientIds = $this->visibleClientIds($user);

        return $clientIds === null || $this->scopeShipmentsForClients(Shipment::query()->whereKey($shipment->getKey()), $clientIds)->exists();
    }

    /** supplier_order_id => the distinct clients whose PI lines the order buys. */
    public function clientsByOrder(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return DB::table('supplier_order_lines as sol')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as p', 'p.id', '=', 'pi.client_id')
            ->whereIn('sol.supplier_order_id', $orderIds)
            ->distinct()
            ->orderBy('p.name')
            ->get(['sol.supplier_order_id', 'p.id', 'p.name'])
            ->groupBy('supplier_order_id');
    }

    /** Active ports for one side ('loading' or 'discharge'), plus those marked for neither: code => name. */
    public function portOptions(string $side): array
    {
        return Lookup::query()->ofList('port')->where('active', true)->get()
            ->filter(fn (Lookup $l) => in_array($l->meta['side'] ?? null, [null, '', $side], true))
            ->mapWithKeys(fn (Lookup $l) => [$l->code => $l->displayName()])
            ->all();
    }

    /**
     * The container board: one row per container with its shipment, its status (and the
     * status's sort), CBM and KG loaded, capacity and fill %, and its attachment count.
     *
     * @param  array{status?: ?string, shipping_company_id?: ?int, client_id?: ?int, soon?: bool, late?: bool}  $filters
     */
    public function board(User $user, array $filters = []): Builder
    {
        $today = Carbon::today()->toDateString();
        $warnUntil = Carbon::today()->addDays(BusinessSetting::current()->eta_warning_days)->toDateString();
        $arrivedSort = $this->arrivedSort();
        $notArrived = 'COALESCE('.self::LAST_SORT.', 0) < '.$arrivedSort;
        $loadedCbm = sprintf(self::LOADED, 'cbm');

        $query = Container::query()
            ->join('shipments', 'shipments.id', '=', 'containers.shipment_id')
            ->leftJoin('parties as sc', 'sc.id', '=', 'shipments.shipping_company_id')
            ->select('containers.*')
            ->addSelect([
                'shipments.number as shipment_number', 'shipments.mode', 'shipments.port_of_loading', 'shipments.port_of_discharge',
                'shipments.etd', 'shipments.eta', 'shipments.shipping_company_id', 'sc.name as shipping_company_name',
            ])
            ->selectRaw(self::LAST_STATUS.' AS status')
            ->selectRaw(self::LAST_SORT.' AS status_sort')
            ->selectRaw($loadedCbm.' AS loaded_cbm')
            ->selectRaw(sprintf(self::LOADED, 'kg').' AS loaded_kg')
            ->selectRaw(sprintf(self::CAPACITY, 'cbm').' AS capacity_cbm')
            ->selectRaw(sprintf(self::CAPACITY, 'max_kg').' AS capacity_kg')
            ->selectRaw('ROUND(100 * '.$loadedCbm.' / '.sprintf(self::CAPACITY, 'cbm').', 0) AS fill_percent')
            ->withCount('attachments');

        if (($clientIds = $this->visibleClientIds($user)) !== null) {
            $this->scopeHoldingClients($query, $clientIds);
        }
        if (filled($filters['status'] ?? null)) {
            $filters['status'] === '_none'
                ? $query->whereRaw(self::LAST_STATUS.' IS NULL')
                : $query->whereRaw(self::LAST_STATUS.' = ?', [$filters['status']]);
        }
        if (filled($filters['shipping_company_id'] ?? null)) {
            $query->where('shipments.shipping_company_id', (int) $filters['shipping_company_id']);
        }
        if (filled($filters['client_id'] ?? null)) {
            $this->scopeHoldingClients($query, [(int) $filters['client_id']]);
        }
        if (! empty($filters['soon'])) {
            $query->whereNotNull('shipments.eta')->where('shipments.eta', '>=', $today)->where('shipments.eta', '<=', $warnUntil)->whereRaw($notArrived);
        }
        if (! empty($filters['late'])) {
            $query->whereNotNull('shipments.eta')->where('shipments.eta', '<', $today)->whereRaw($notArrived);
        }

        return $query
            ->orderByRaw('CASE WHEN '.$notArrived.' THEN 0 ELSE 1 END')
            ->orderByRaw('shipments.eta IS NULL, shipments.eta')
            ->orderBy('containers.id');
    }

    /**
     * How a row's ETA reads today: ['late' => N] when past ETA and not arrived, ['soon' => N]
     * when arriving within the warning days, or [] otherwise.
     */
    public function etaFlag(?string $eta, ?int $statusSort, ?int $arrivedSort = null): array
    {
        if ($eta === null || $eta === '') {
            return [];
        }
        if (($statusSort ?? 0) >= ($arrivedSort ?? $this->arrivedSort())) {
            return [];
        }
        $days = (int) Carbon::today()->diffInDays(Carbon::parse($eta)->startOfDay(), false);
        if ($days < 0) {
            return ['late' => -$days];
        }
        if ($days <= BusinessSetting::current()->eta_warning_days) {
            return ['soon' => $days];
        }

        return [];
    }

    /** container_id => a collection of the distinct clients inside (net of unloads). */
    public function clientsByContainer(array $containerIds, ?array $clientIds = null): Collection
    {
        if ($containerIds === []) {
            return collect();
        }

        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as p', 'p.id', '=', 'pi.client_id')
            ->whereIn('cl.container_id', $containerIds)
            ->when($clientIds !== null, fn ($q) => $q->whereIn('pi.client_id', $clientIds))
            ->groupBy('cl.container_id', 'cl.supplier_order_line_id', 'p.id', 'p.name')
            ->havingRaw('SUM(cl.cartons) > 0')
            ->orderBy('p.name')
            ->get(['cl.container_id', 'p.id', 'p.name'])
            ->groupBy('container_id')
            ->map(fn (Collection $rows) => $rows->unique('id')->values());
    }

    /**
     * What is inside the containers, per supplier order line, net of unloads: container_id,
     * line ids, client, PI, supplier order (and its supplier), product, description, unit,
     * cartons, qty, CBM and KG.
     */
    public function contents(array $containerIds, ?array $clientIds = null): Collection
    {
        if ($containerIds === []) {
            return collect();
        }

        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('supplier_orders as so', 'so.id', '=', 'sol.supplier_order_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as cp', 'cp.id', '=', 'pi.client_id')
            ->leftJoin('parties as sp', 'sp.id', '=', 'so.supplier_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pl.product_id')
            ->whereIn('cl.container_id', $containerIds)
            ->when($clientIds !== null, fn ($q) => $q->whereIn('pi.client_id', $clientIds))
            ->groupBy('cl.container_id', 'sol.id', 'so.id', 'pl.id', 'pi.id', 'cp.id', 'sp.id', 'pr.id')
            ->havingRaw('SUM(cl.cartons) > 0')
            ->orderBy('cl.container_id')->orderBy('cp.name')->orderBy('pi.pi_number')->orderBy('pl.line_no')
            ->select([
                'cl.container_id', 'sol.id as supplier_order_line_id', 'so.id as supplier_order_id', 'so.number as supplier_order_number',
                'sp.name as supplier_name', 'pl.id as pi_line_id', 'pl.line_no', 'pl.description', 'pl.unit',
                'pi.id as proforma_invoice_id', 'pi.pi_number', 'pi.revision', 'cp.id as client_id', 'cp.name as client_name',
                'pr.id as product_id', 'pr.code as product_code', 'pr.name as product_name', 'pr.photo as product_photo',
            ])
            ->selectRaw('SUM(cl.cartons) AS cartons')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.qty_per_carton, 4) AS qty')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.cbm_per_carton, 4) AS cbm')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.kg_per_carton, 4) AS kg')
            ->get();
    }

    /**
     * One client's goods in a container at the client's prices, for the commercial invoice:
     * per PI line, net of unloads, with the product's HS code and the PI's currency.
     */
    public function invoiceLines(int $containerId, int $clientId): Collection
    {
        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pl.product_id')
            ->where('cl.container_id', $containerId)->where('pi.client_id', $clientId)
            ->groupBy('pl.id', 'pi.id', 'pr.id')
            ->havingRaw('SUM(cl.cartons) > 0')
            ->orderBy('pi.pi_number')->orderBy('pl.line_no')
            ->select(['pl.id', 'pl.description', 'pl.unit', 'pl.unit_price', 'pl.qty_per_carton', 'pi.pi_number', 'pi.revision', 'pi.po_number', 'pi.currency',
                'pr.code as product_code', 'pr.hs_code'])
            ->selectRaw('SUM(cl.cartons) AS cartons')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.qty_per_carton, 4) AS qty')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.qty_per_carton * pl.unit_price, 4) AS amount')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.cbm_per_carton, 4) AS cbm')
            ->selectRaw('ROUND(SUM(cl.cartons) * pl.kg_per_carton, 4) AS kg')
            ->get();
    }

    /** Totals of contents(): cartons, qty, cbm, kg — summed in SQL. */
    public function contentTotals(array $containerIds, ?array $clientIds = null): object
    {
        $totals = DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->whereIn('cl.container_id', $containerIds ?: [0])
            ->when($clientIds !== null, fn ($q) => $q->whereIn('pi.client_id', $clientIds))
            ->selectRaw('COALESCE(SUM(cl.cartons), 0) AS cartons')
            ->selectRaw('COALESCE(ROUND(SUM(cl.cartons * pl.qty_per_carton), 4), 0) AS qty')
            ->selectRaw('COALESCE(ROUND(SUM(cl.cartons * pl.cbm_per_carton), 4), 0) AS cbm')
            ->selectRaw('COALESCE(ROUND(SUM(cl.cartons * pl.kg_per_carton), 4), 0) AS kg')
            ->first();

        return $totals;
    }

    /** One container as a board row (status, loaded CBM/KG, capacity, fill %). */
    public function boardRow(Container $container): ?Container
    {
        return Container::query()
            ->whereKey($container->getKey())
            ->select('containers.id')
            ->selectRaw(self::LAST_STATUS.' AS status')
            ->selectRaw(self::LAST_SORT.' AS status_sort')
            ->selectRaw(sprintf(self::LOADED, 'cbm').' AS loaded_cbm')
            ->selectRaw(sprintf(self::LOADED, 'kg').' AS loaded_kg')
            ->selectRaw(sprintf(self::CAPACITY, 'cbm').' AS capacity_cbm')
            ->selectRaw(sprintf(self::CAPACITY, 'max_kg').' AS capacity_kg')
            ->selectRaw('ROUND(100 * '.sprintf(self::LOADED, 'cbm').' / '.sprintf(self::CAPACITY, 'cbm').', 0) AS fill_percent')
            ->selectRaw('ROUND(100 * '.sprintf(self::LOADED, 'kg').' / '.sprintf(self::CAPACITY, 'max_kg').', 0) AS fill_kg_percent')
            ->selectRaw('COALESCE('.sprintf(self::LOADED, 'cbm').' > '.sprintf(self::CAPACITY, 'cbm').', false) AS over_cbm')
            ->selectRaw('COALESCE('.sprintf(self::LOADED, 'kg').' > '.sprintf(self::CAPACITY, 'max_kg').', false) AS over_kg')
            ->first();
    }

    /**
     * Lines of the orders booked on $shipment that still have goods to load (received −
     * loaded > 0): supplier order line id, order number, client, PI, description, received,
     * loaded and available cartons.
     */
    public function loadableLines(Shipment $shipment, ?array $clientIds = null): Collection
    {
        $received = '(SELECT COALESCE(SUM(gr.cartons), 0) FROM goods_receipts gr WHERE gr.supplier_order_line_id = sol.id)';
        $loaded = '(SELECT COALESCE(SUM(cl.cartons), 0) FROM container_loads cl WHERE cl.supplier_order_line_id = sol.id)';

        return DB::table('shipment_orders as so_b')
            ->join('supplier_orders as so', 'so.id', '=', 'so_b.supplier_order_id')
            ->join('supplier_order_lines as sol', 'sol.supplier_order_id', '=', 'so.id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as cp', 'cp.id', '=', 'pi.client_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pl.product_id')
            ->where('so_b.shipment_id', $shipment->id)
            ->when($clientIds !== null, fn ($q) => $q->whereIn('pi.client_id', $clientIds))
            ->whereRaw("$received - $loaded > 0")
            ->orderBy('so.number')->orderBy('pl.line_no')
            ->select([
                'sol.id as supplier_order_line_id', 'so.id as supplier_order_id', 'so.number as supplier_order_number',
                'pi.pi_number', 'cp.name as client_name', 'pl.line_no', 'pl.description', 'pr.name as product_name',
            ])
            ->selectRaw("$received AS received")
            ->selectRaw("$loaded AS loaded")
            ->selectRaw("$received - $loaded AS available")
            ->get();
    }

    /** Received and loaded cartons of one supplier order line, summed in SQL. */
    public function lineCartons(int $supplierOrderLineId): object
    {
        return DB::selectOne(
            'SELECT (SELECT COALESCE(SUM(cartons), 0) FROM goods_receipts WHERE supplier_order_line_id = ?) AS received,
                    (SELECT COALESCE(SUM(cartons), 0) FROM container_loads WHERE supplier_order_line_id = ?) AS loaded,
                    (SELECT COALESCE(SUM(cartons), 0) FROM goods_receipts WHERE supplier_order_line_id = ?)
                  - (SELECT COALESCE(SUM(cartons), 0) FROM container_loads WHERE supplier_order_line_id = ?) AS available',
            [$supplierOrderLineId, $supplierOrderLineId, $supplierOrderLineId, $supplierOrderLineId],
        );
    }

    /** True when every carton received for $order has been loaded (and something was received). */
    public function orderFullyLoaded(SupplierOrder $order): bool
    {
        $row = DB::selectOne(
            'SELECT (SELECT COALESCE(SUM(gr.cartons), 0) FROM goods_receipts gr JOIN supplier_order_lines l ON l.id = gr.supplier_order_line_id WHERE l.supplier_order_id = ?) AS received,
                    (SELECT COALESCE(SUM(cl.cartons), 0) FROM container_loads cl JOIN supplier_order_lines l ON l.id = cl.supplier_order_line_id WHERE l.supplier_order_id = ?) AS loaded',
            [$order->id, $order->id],
        );

        return (bool) DB::selectOne('SELECT (CAST(? AS numeric) > 0 AND CAST(? AS numeric) >= CAST(? AS numeric)) AS full', [$row->received, $row->loaded, $row->received])->full;
    }

    /** True when goods of $order are still loaded (net) on a container of $shipment — the unbook trigger's rule. */
    public function hasGoodsOnShipment(Shipment $shipment, SupplierOrder $order): bool
    {
        return DB::table('container_loads as cl')
            ->join('containers as c', 'c.id', '=', 'cl.container_id')
            ->join('supplier_order_lines as l', 'l.id', '=', 'cl.supplier_order_line_id')
            ->where('c.shipment_id', $shipment->id)
            ->where('l.supplier_order_id', $order->id)
            ->groupBy('cl.container_id', 'cl.supplier_order_line_id')
            ->havingRaw('SUM(cl.cartons) <> 0')
            ->selectRaw('1')
            ->exists();
    }

    /** Net cartons of one supplier order line inside one container. */
    public function cartonsInContainer(Container $container, int $supplierOrderLineId): string
    {
        return (string) DB::selectOne(
            'SELECT COALESCE(SUM(cartons), 0) AS cartons FROM container_loads WHERE container_id = ? AND supplier_order_line_id = ?',
            [$container->id, $supplierOrderLineId],
        )->cartons;
    }

    /** True when $shipment has $order booked on it. */
    public function isBooked(Shipment $shipment, SupplierOrder $order): bool
    {
        return DB::table('shipment_orders')->where('shipment_id', $shipment->id)->where('supplier_order_id', $order->id)->exists();
    }

    /**
     * Whether the PI of $line is paid in full, per the plan's contract: the SUM(signed_amount)
     * of client_receipt payments in the PI's currency, over every revision of the PI number,
     * against the total of the current revision.
     *
     * @return array{pi_number: string, currency: string, total: string, paid: string, fully_paid: bool}
     */
    public function piPaidStatus(PiLine $line): array
    {
        $pi = $line->proformaInvoice;
        $current = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->where('is_current', true)->first() ?? $pi;

        $row = DB::selectOne(
            "SELECT (SELECT COALESCE(SUM(amount), 0) FROM pi_lines WHERE proforma_invoice_id = ?) AS total,
                    (SELECT COALESCE(SUM(p.signed_amount), 0) FROM payments p
                       JOIN proforma_invoices r ON r.id = p.proforma_invoice_id
                      WHERE r.pi_number = ? AND p.kind = 'client_receipt' AND p.currency = ?) AS paid",
            [$current->id, $current->pi_number, $current->currency],
        );
        // A credit note settles part of the PI as surely as a receipt (spec §5.8).
        $credited = app(NoteQueries::class)->creditedForPiNumber($current->pi_number, $current->currency);
        $full = bccomp(bcadd((string) $row->paid, $credited, 4), (string) $row->total, 4) >= 0;

        return [
            'pi_number' => $current->pi_number,
            'currency' => $current->currency,
            'total' => (string) $row->total,
            'paid' => (string) $row->paid,
            'credited' => $credited,
            'fully_paid' => $full,
        ];
    }
}
