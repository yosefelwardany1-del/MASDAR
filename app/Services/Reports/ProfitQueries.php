<?php

namespace App\Services\Reports;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\ProformaInvoice;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingService;
use App\Services\Finance\PaymentQueries;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit per sales order (PI) and per container (spec §5.8), in the book currency. Read-only,
 * computed every time from the documents — never stored:
 *
 *   sales        the PI's lines (current revision), less its issued credit notes;
 *   goods cost   the supplier order lines that carry its lines (any revision, not released),
 *                less the PI's share of those orders' issued debit notes; a line not ordered
 *                yet counts at its supplier price and marks the figure as estimated;
 *   freight      each container's costs — its posted shipping bill and the expenses paid
 *                against it — split between the PIs loaded in it by CBM, weight, value or
 *                cartons (setting container_cost_split, spec Q-C3);
 *   other costs  expenses paid against the PI.
 *
 * Amounts in another currency are converted at the rate of their date: the PI's at its
 * confirmation (else issue, else today), an order's at its confirmation (else creation), a
 * payment at its own rate. A missing rate is never guessed: the row says which one is missing
 * and stays out of the totals.
 */
class ProfitQueries
{
    /** @var array<string, string> currency|date => rate, for this report only */
    private array $rates = [];

    public function __construct(private Ledger $ledger, private PaymentQueries $payments) {}

    public function bookCurrency(): string
    {
        return $this->ledger->bookCurrency();
    }

    public function split(): string
    {
        return (string) BusinessSetting::current()->container_cost_split;
    }

    /**
     * One row per PI.
     *
     * @param  iterable<ProformaInvoice>  $pis  current revisions
     * @return list<array<string, mixed>>
     */
    public function forPis(iterable $pis): array
    {
        $pis = collect($pis);
        if ($pis->isEmpty()) {
            return [];
        }
        $numbers = $pis->pluck('pi_number')->unique()->values()->all();
        $shares = $this->containerSharesByPi($this->containersOfPis($numbers));

        return $pis->map(fn (ProformaInvoice $pi) => $this->piRow($pi, $shares[$pi->pi_number] ?? []))->values()->all();
    }

    /** @return array<string, mixed> */
    public function forPi(ProformaInvoice $pi): array
    {
        return $this->forPis([$pi])[0];
    }

    /**
     * One row per container: the goods loaded at their sales and cost prices, the container's
     * costs, and each client's part.
     *
     * @param  iterable<Container>  $containers
     * @return list<array<string, mixed>>
     */
    public function forContainers(iterable $containers): array
    {
        $containers = collect($containers);
        if ($containers->isEmpty()) {
            return [];
        }
        $ids = $containers->pluck('id')->all();
        $loads = $this->loads($ids)->groupBy('container_id');
        $costs = $this->containerCosts($ids);

        return $containers->map(function (Container $container) use ($loads, $costs) {
            $rows = $loads->get($container->id, collect());
            $cost = $costs[$container->id] ?? '0';
            $row = ['container' => $container, 'container_cost' => $cost, 'clients' => [], 'error' => null,
                'sales' => '0', 'goods_cost' => '0', 'profit' => '0', 'margin' => null];
            try {
                $valued = $rows->map(fn ($load) => $this->valueLoad($load));
                $totalBasis = Decimal::sum($valued->pluck('basis'));
                $byClient = $valued->groupBy('client_id');
                foreach ($byClient as $clientId => $group) {
                    $basis = Decimal::sum($group->pluck('basis'));
                    $sales = Decimal::sum($group->pluck('sales'));
                    $goods = Decimal::sum($group->pluck('cost'));
                    $share = $this->share($cost, $basis, $totalBasis);
                    $profit = Decimal::sub(Decimal::sub($sales, $goods), $share);
                    $row['clients'][] = [
                        'client_id' => (int) $clientId, 'client' => $group->first()->client_name,
                        'pi_numbers' => $group->pluck('pi_number')->unique()->values()->all(),
                        'basis' => $basis, 'sales' => $sales, 'goods_cost' => $goods, 'container_cost' => $share,
                        'profit' => $profit, 'margin' => $this->margin($profit, $sales),
                    ];
                }
                $row['sales'] = Decimal::sum($valued->pluck('sales'));
                $row['goods_cost'] = Decimal::sum($valued->pluck('cost'));
                $row['profit'] = Decimal::sub(Decimal::sub($row['sales'], $row['goods_cost']), $cost);
                $row['margin'] = $this->margin($row['profit'], $row['sales']);
            } catch (AccountingException $e) {
                $row['error'] = $e->getMessage();
            }

            return $row;
        })->values()->all();
    }

    /**
     * Each container's costs in the book currency: its posted shipping bills (the expense side,
     * net of reversals) and the outgoing expenses that count paid against it.
     *
     * @param  list<int>  $containerIds
     * @return array<int, string>
     */
    public function containerCosts(array $containerIds): array
    {
        if ($containerIds === []) {
            return [];
        }
        $bills = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.status', 'posted')->where('e.source_type', PostingService::SOURCE_CONTAINER)
            ->whereIn('e.source_id', $containerIds)->where('a.type', 'expense')
            ->groupBy('e.source_id')
            ->selectRaw('e.source_id AS id, SUM(l.debit) - SUM(l.credit) AS total')
            ->pluck('total', 'id');

        $paid = $this->payments->whereEffective(DB::table('payments'))
            ->whereIn('payments.container_id', $containerIds)
            ->whereIn('payments.kind', ['expense', 'other_out'])
            ->groupBy('payments.container_id')
            ->selectRaw('payments.container_id AS id, -SUM(payments.signed_book_amount) AS total')
            ->pluck('total', 'id');

        $out = [];
        foreach ($containerIds as $id) {
            $out[$id] = Decimal::add(Decimal::normalize((string) ($bills[$id] ?? '0')), Decimal::normalize((string) ($paid[$id] ?? '0')));
        }

        return $out;
    }

    // ---------------------------------------------------------------- one PI

    /** @param array<int, string> $shares container_id => this PI's share of its costs */
    private function piRow(ProformaInvoice $pi, array $shares): array
    {
        $row = [
            'pi' => $pi, 'currency' => $pi->currency, 'error' => null, 'estimated' => false, 'cost_missing' => false,
            'sales_doc' => '0', 'sales' => '0', 'credited' => '0', 'goods_cost' => '0', 'debited' => '0',
            'container_cost' => Decimal::sum(array_values($shares)), 'containers' => count($shares),
            'other_cost' => '0', 'fees' => '0', 'profit' => '0', 'margin' => null,
        ];

        try {
            $salesDoc = Decimal::normalize((string) DB::table('pi_lines')->where('proforma_invoice_id', $pi->id)->sum('amount'));
            $credited = Decimal::normalize((string) DB::table('trade_notes as n')->join('trade_note_lines as l', 'l.trade_note_id', '=', 'n.id')
                ->join('proforma_invoices as r', 'r.id', '=', 'n.proforma_invoice_id')
                ->where('n.kind', 'credit')->where('n.status', 'issued')->where('r.pi_number', $pi->pi_number)
                ->where('n.currency', $pi->currency)->sum('l.amount'));
            $rate = $this->rate($pi->currency, $this->piDate($pi));
            $row['sales_doc'] = $salesDoc;
            $row['sales'] = Decimal::mul($salesDoc, $rate);
            $row['credited'] = Decimal::mul($credited, $rate);

            // Goods ordered from factories, on any revision of the PI.
            $ordered = DB::table('supplier_order_lines as sol')
                ->join('supplier_orders as so', 'so.id', '=', 'sol.supplier_order_id')
                ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
                ->join('proforma_invoices as r', 'r.id', '=', 'pl.proforma_invoice_id')
                ->where('r.pi_number', $pi->pi_number)->whereNull('sol.released_at')->where('so.status', '<>', 'cancelled')
                ->get(['sol.amount', 'so.id as order_id', 'so.currency', 'so.confirmation_date', 'so.created_at']);
            $goods = '0';
            $debited = '0';
            foreach ($ordered->groupBy('order_id') as $orderId => $lines) {
                $first = $lines->first();
                $orderRate = $this->rate($first->currency, $first->confirmation_date ?: substr((string) $first->created_at, 0, 10));
                $mine = Decimal::sum($lines->pluck('amount')->map(fn ($v) => (string) $v));
                $goods = Decimal::add($goods, Decimal::mul($mine, $orderRate));
                // This PI's part of the order's debit notes, by its share of the order's value.
                $orderTotal = Decimal::normalize((string) DB::table('supplier_order_lines')->where('supplier_order_id', $orderId)->whereNull('released_at')->sum('amount'));
                $orderDebited = Decimal::normalize((string) DB::table('trade_notes as n')->join('trade_note_lines as l', 'l.trade_note_id', '=', 'n.id')
                    ->where('n.kind', 'debit')->where('n.status', 'issued')->where('n.supplier_order_id', $orderId)
                    ->where('n.currency', $first->currency)->sum('l.amount'));
                if (Decimal::isPositive($orderDebited) && Decimal::isPositive($orderTotal)) {
                    $debited = Decimal::add($debited, Decimal::mul($this->share($orderDebited, $mine, $orderTotal), $orderRate));
                }
            }

            // Lines of the current revision on no order yet: their supplier price, if known.
            $open = DB::table('pi_lines')->where('proforma_invoice_id', $pi->id)
                ->whereRaw('pi_lines.id NOT IN ('.SupplierOrderService::blockedLineIdsSql().')')
                ->get(['total_qty', 'supplier_price', 'supplier_currency']);
            $fallbackCurrency = (string) BusinessSetting::current()->supplier_currency;
            foreach ($open as $line) {
                if ($line->supplier_price === null) {
                    $row['cost_missing'] = true;

                    continue;
                }
                $row['estimated'] = true;
                $amount = Decimal::mul((string) $line->total_qty, (string) $line->supplier_price);
                $goods = Decimal::add($goods, Decimal::mul($amount, $this->rate($line->supplier_currency ?: $fallbackCurrency, now()->toDateString())));
            }
            $row['goods_cost'] = $goods;
            $row['debited'] = $debited;

            $row['other_cost'] = Decimal::normalize((string) $this->payments->whereEffective(DB::table('payments'))
                ->join('proforma_invoices as r', 'r.id', '=', 'payments.proforma_invoice_id')
                ->where('r.pi_number', $pi->pi_number)->whereIn('payments.kind', ['expense', 'other_out'])
                ->selectRaw('COALESCE(-SUM(payments.signed_book_amount), 0) AS total')->value('total'));

            // Service invoices on the PI (commission, inspection …), each at the rate of its date.
            foreach (DB::table('service_invoices as s')->join('proforma_invoices as r', 'r.id', '=', 's.proforma_invoice_id')
                ->where('s.status', 'issued')->where('r.pi_number', $pi->pi_number)
                ->get(['s.id', 's.currency', 's.issued_on']) as $si) {
                $amount = Decimal::normalize((string) DB::table('service_invoice_lines')->where('service_invoice_id', $si->id)->sum('amount'));
                $row['fees'] = Decimal::add($row['fees'], Decimal::mul($amount, $this->rate($si->currency, (string) $si->issued_on)));
            }

            $net = Decimal::add(Decimal::sub($row['sales'], $row['credited']), $row['fees']);
            $row['profit'] = Decimal::sub(Decimal::sub(Decimal::sub($net, Decimal::sub($goods, $debited)), $row['container_cost']), $row['other_cost']);
            $row['margin'] = $this->margin($row['profit'], $net);
        } catch (AccountingException $e) {
            $row['error'] = $e->getMessage();
        }

        return $row;
    }

    // ---------------------------------------------------------------- containers and loads

    /** @return list<int> the containers holding goods of these PI numbers */
    private function containersOfPis(array $numbers): array
    {
        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as r', 'r.id', '=', 'pl.proforma_invoice_id')
            ->whereIn('r.pi_number', $numbers)
            ->distinct()->pluck('cl.container_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, array<int, string>> pi_number => container_id => share of its costs */
    private function containerSharesByPi(array $containerIds): array
    {
        if ($containerIds === []) {
            return [];
        }
        $costs = $this->containerCosts($containerIds);
        $out = [];
        foreach ($this->loads($containerIds)->groupBy('container_id') as $containerId => $rows) {
            $valued = $rows->map(function ($load) {
                try {
                    return $this->valueLoad($load);
                } catch (AccountingException) {
                    // A value split needs the rate; without it, that load weighs nothing here.
                    return (object) ['pi_number' => $load->pi_number, 'basis' => '0'];
                }
            });
            $total = Decimal::sum($valued->pluck('basis'));
            foreach ($valued->groupBy('pi_number') as $number => $group) {
                $out[$number][(int) $containerId] = $this->share($costs[$containerId] ?? '0', Decimal::sum($group->pluck('basis')), $total);
            }
        }

        return $out;
    }

    /** Every load in these containers with what it needs to be valued and weighed. */
    private function loads(array $containerIds): Collection
    {
        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('supplier_orders as so', 'so.id', '=', 'sol.supplier_order_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as r', 'r.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'r.client_id')
            ->whereIn('cl.container_id', $containerIds)
            ->orderBy('cl.id')
            ->get([
                'cl.container_id', 'cl.cartons',
                'sol.qty_per_carton as order_qty_per_carton', 'sol.unit_price as order_price',
                'so.currency as order_currency', 'so.confirmation_date', 'so.created_at as order_created_at',
                'pl.qty_per_carton', 'pl.unit_price', 'pl.cbm_per_carton', 'pl.kg_per_carton',
                'r.pi_number', 'r.currency', 'r.confirmed_at', 'r.issued_at', 'r.client_id', 'c.name as client_name',
            ]);
    }

    /** A load's sales value and goods cost in the book currency, and its weight in the split. */
    private function valueLoad(object $load): object
    {
        $cartons = (string) $load->cartons;
        $piDate = $this->dateOf($load->confirmed_at ?? $load->issued_at);
        $sales = Decimal::mul(Decimal::mul(Decimal::mul($cartons, (string) $load->qty_per_carton), (string) $load->unit_price), $this->rate($load->currency, $piDate));
        $orderDate = $load->confirmation_date ?: substr((string) $load->order_created_at, 0, 10);
        $cost = Decimal::mul(Decimal::mul(Decimal::mul($cartons, (string) $load->order_qty_per_carton), (string) $load->order_price), $this->rate($load->order_currency, $orderDate));
        $basis = match ($this->split()) {
            'kg' => Decimal::mul($cartons, (string) $load->kg_per_carton),
            'value' => $sales,
            'cartons' => Decimal::normalize($cartons),
            default => Decimal::mul($cartons, (string) $load->cbm_per_carton),
        };

        return (object) [
            'pi_number' => $load->pi_number, 'client_id' => $load->client_id, 'client_name' => $load->client_name,
            'sales' => $sales, 'cost' => $cost, 'basis' => $basis,
        ];
    }

    // ---------------------------------------------------------------- arithmetic

    /** $amount × $part ÷ $whole; when nothing weighs (no CBM entered), an even split is not guessed: zero. */
    private function share(string $amount, string $part, string $whole): string
    {
        if (! Decimal::isPositive($whole)) {
            return '0';
        }

        return Decimal::normalize(bcdiv(bcmul($amount, $part, 10), $whole, 4));
    }

    private function margin(string $profit, string $sales): ?string
    {
        return Decimal::isPositive($sales) ? bcdiv(bcmul($profit, '100', 8), $sales, 1) : null;
    }

    private function rate(string $currency, string $date): string
    {
        return $this->rates[$currency.'|'.$date] ??= $this->ledger->rateOn($currency, $date);
    }

    private function piDate(ProformaInvoice $pi): string
    {
        return $this->dateOf($pi->confirmed_at ?? $pi->issued_at);
    }

    private function dateOf(mixed $stamp): string
    {
        if ($stamp === null) {
            return now()->toDateString();
        }

        return Carbon::parse($stamp)->timezone(config('app.timezone'))->toDateString();
    }
}
