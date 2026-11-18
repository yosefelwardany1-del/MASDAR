<?php

namespace App\Services\Shipping;

use App\Models\User;
use App\Support\Access\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Goods in MASDAR's hands in Yiwu (spec §5.4, §5.8): per supplier order line, the cartons
 * received less those loaded, computed — never stored — with the CBM, where they are and how long
 * they have waited. Read-only.
 */
class WarehouseStock
{
    public function lines(User $user): Collection
    {
        $received = DB::table('goods_receipts')->groupBy('supplier_order_line_id')
            ->selectRaw('supplier_order_line_id, SUM(cartons) AS cartons, MIN(received_on) AS first_on')
            ->selectRaw('(ARRAY_AGG(location ORDER BY id DESC) FILTER (WHERE location IS NOT NULL))[1] AS location');
        $loaded = DB::table('container_loads')->groupBy('supplier_order_line_id')->selectRaw('supplier_order_line_id, SUM(cartons) AS cartons');

        return DB::table('supplier_order_lines as sol')
            ->joinSub($received, 'r', 'r.supplier_order_line_id', '=', 'sol.id')
            ->leftJoinSub($loaded, 'l', 'l.supplier_order_line_id', '=', 'sol.id')
            ->join('supplier_orders as so', 'so.id', '=', 'sol.supplier_order_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->join('parties as s', 's.id', '=', 'so.supplier_id')
            ->whereRaw('r.cartons - COALESCE(l.cartons, 0) > 0')
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->where('c.account_manager_id', $user->id))
            ->orderBy('r.first_on')->orderBy('c.name')
            ->get([
                'sol.id', 'so.id as order_id', 'so.number as order_number', 's.name as supplier', 'c.id as client_id', 'c.name as client',
                'pi.pi_number', 'pi.revision', 'pl.description', 'pl.cbm_per_carton', 'pl.kg_per_carton',
                'r.cartons as received', 'r.first_on', 'r.location',
            ])
            ->map(function ($row) {
                $row->loaded = (string) (DB::table('container_loads')->where('supplier_order_line_id', $row->id)->sum('cartons'));
                $row->in_stock = bcsub((string) $row->received, $row->loaded, 4);
                $row->cbm = bcmul($row->in_stock, (string) $row->cbm_per_carton, 4);
                $row->kg = bcmul($row->in_stock, (string) $row->kg_per_carton, 4);
                $row->days = (int) now()->startOfDay()->diffInDays(Carbon::parse($row->first_on)->startOfDay(), true);

                return $row;
            });
    }
}
