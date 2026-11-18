<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Services\Accounting\Decimal;
use App\Services\Reports\ProfitQueries;
use App\Services\Shipping\ShippingQueries;
use App\Support\Access\Visibility;
use App\Support\IO\CsvSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Profit per sales order and per container (spec §5.8). The figures carry factories' prices,
 * so besides the `profit` screen the user must see supplier prices; a sales user limited to
 * their own clients sees only theirs.
 */
class ProfitController extends Controller
{
    public function __construct(private ProfitQueries $profit) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->assertSeesCosts($user);
        [$from, $to] = $this->range($request);
        $clientId = $request->integer('client') ?: null;

        $query = ProformaInvoice::query()->current()->whereIn('status', ['issued', 'accepted', 'confirmed'])
            ->whereHas('client', fn ($q) => Visibility::scopeClients($q, $user))
            ->whereRaw('COALESCE(confirmed_at, issued_at)::date BETWEEN ? AND ?', [$from, $to])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->with('client')
            ->orderByRaw('COALESCE(confirmed_at, issued_at) DESC')->orderByDesc('id');

        $rows = $this->profit->forPis($query->get());
        $totals = $this->totals($rows, ['sales', 'credited', 'fees', 'goods_cost', 'debited', 'container_cost', 'other_cost', 'profit']);

        if ($request->query('format') === 'csv') {
            return $this->csv("profit-by-order-{$from}-{$to}.csv",
                [__('PI'), __('Client'), __('Status'), __('Sales'), __('Credit notes'), __('Goods cost'), __('Debit notes'), __('Freight and container costs'), __('Other costs'), __('Profit'), __('Margin %'), __('Note')],
                array_map(fn ($r) => [
                    $r['pi']->reference(), $r['pi']->client?->name, $r['pi']->status, $r['sales'], $r['credited'], $r['goods_cost'], $r['debited'],
                    $r['container_cost'], $r['other_cost'], $r['profit'], $r['margin'] ?? '',
                    $r['error'] ?? ($r['estimated'] ? __('Estimated: some lines are not ordered yet') : ''),
                ], $rows));
        }

        return view('profit.index', [
            'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to, 'clientId' => $clientId,
            'clients' => Visibility::scopeClients(Party::query()->clients(), $user)->orderBy('name')->get(['id', 'code', 'name']),
            'book' => $this->profit->bookCurrency(), 'split' => $this->profit->split(),
        ]);
    }

    public function containers(Request $request, ShippingQueries $shipping)
    {
        $user = $request->user();
        $this->assertSeesCosts($user);
        [$from, $to] = $this->range($request);
        $visible = $shipping->visibleClientIds($user);

        $containers = Container::query()->with('shipment')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('container_loads as cl')->whereColumn('cl.container_id', 'containers.id'))
            ->whereHas('shipment', fn ($q) => $q->whereRaw('COALESCE(etd, created_at::date) BETWEEN ? AND ?', [$from, $to]))
            ->when($visible !== null, fn ($q) => $shipping->scopeHoldingClients($q, $visible))
            ->orderByDesc('id')->limit(200)->get();

        $rows = $this->profit->forContainers($containers);
        if ($visible !== null) {
            // Someone limited to their own clients sees only their clients' part of a shared container.
            $rows = array_map(function ($r) use ($visible) {
                $r['clients'] = array_values(array_filter($r['clients'], fn ($c) => in_array($c['client_id'], $visible, true)));
                $r['partial'] = true;

                return $r;
            }, $rows);
        }
        $totals = $this->totals($rows, ['sales', 'goods_cost', 'container_cost', 'profit']);

        if ($request->query('format') === 'csv') {
            return $this->csv("profit-by-container-{$from}-{$to}.csv",
                [__('Container'), __('Shipment'), __('ETD'), __('Sales'), __('Goods cost'), __('Freight and container costs'), __('Profit'), __('Margin %'), __('Note')],
                array_map(fn ($r) => [
                    $r['container']->container_no ?: '#'.$r['container']->id, $r['container']->shipment?->number, $r['container']->shipment?->etd?->toDateString(),
                    $r['sales'], $r['goods_cost'], $r['container_cost'], $r['profit'], $r['margin'] ?? '', $r['error'] ?? '',
                ], $rows));
        }

        return view('profit.containers', [
            'rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to,
            'book' => $this->profit->bookCurrency(), 'split' => $this->profit->split(), 'limited' => $visible !== null,
        ]);
    }

    public function pi(Request $request, ProformaInvoice $pi)
    {
        $user = $request->user();
        $this->assertSeesCosts($user);
        $current = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->where('is_current', true)->first() ?? $pi;
        abort_unless(Visibility::scopeClients(Party::query()->whereKey($current->client_id), $user)->exists(), 404);
        $current->load('client');
        $row = $this->profit->forPi($current);
        $containerIds = DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as r', 'r.id', '=', 'pl.proforma_invoice_id')
            ->where('r.pi_number', $current->pi_number)->distinct()->pluck('cl.container_id')->all();

        return view('profit.pi', [
            'row' => $row, 'pi' => $current, 'book' => $this->profit->bookCurrency(), 'split' => $this->profit->split(),
            'containers' => $this->profit->forContainers(Container::query()->with('shipment')->whereKey($containerIds)->get()),
        ]);
    }

    private function assertSeesCosts($user): void
    {
        abort_unless(Visibility::canSeeSupplierPrices($user), 403, __('Profit shows factories\' prices: it is for those who may see supplier prices.'));
    }

    /** @return array<string, string> */
    private function totals(array $rows, array $keys): array
    {
        $totals = array_fill_keys($keys, '0');
        foreach ($rows as $row) {
            if ($row['error'] ?? null) {
                continue;
            }
            foreach ($keys as $key) {
                $totals[$key] = Decimal::add($totals[$key], $row[$key]);
            }
        }
        $totals['margin'] = Decimal::isPositive($totals['sales'])
            ? bcdiv(bcmul($totals['profit'], '100', 8), Decimal::add(Decimal::sub($totals['sales'], $totals['credited'] ?? '0'), $totals['fees'] ?? '0'), 1)
            : null;

        return $totals;
    }

    /** @return array{string, string} the year to date unless given */
    private function range(Request $request): array
    {
        $date = fn ($v, $default) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
        $to = $date($request->query('to'), now()->toDateString());
        $from = $date($request->query('from'), Carbon::parse($to)->startOfYear()->toDateString());

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    private function csv(string $name, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => CsvSanitizer::cell((string) $c), $header));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^-?\d+(\.\d+)?$/', $cell)
                    ? $cell
                    : CsvSanitizer::cell((string) ($cell ?? '')), $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
