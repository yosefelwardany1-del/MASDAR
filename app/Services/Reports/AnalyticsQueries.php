<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\Ledger;
use App\Support\Access\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchase analysis (spec §5.8), read-only, in the book currency. Sales are the
 * confirmed PIs (current revision) by their confirmation date; purchases the supplier orders
 * (not cancelled, lines not released) by their confirmation date. Each document converts at the
 * rate of its own date; one without a rate is left out and counted, never guessed. A sales user
 * limited to their own clients sees only theirs; purchases name factories, so they need supplier
 * prices to be visible.
 */
class AnalyticsQueries
{
    /** @var array<string, string> */
    private array $rates = [];

    /** @var array<string, int> currency => documents left out for want of a rate */
    public array $missingRates = [];

    public function __construct(private Ledger $ledger) {}

    public function bookCurrency(): string
    {
        return $this->ledger->bookCurrency();
    }

    /**
     * Every confirmed PI in the range with its value in the book currency.
     *
     * @return Collection<int, object> pi_id, pi_number, client_id, client, manager_id, manager, month, book
     */
    public function sales(string $from, string $to, User $user): Collection
    {
        $tz = config('app.timezone');
        $rows = DB::table('proforma_invoices as pi')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.account_manager_id')
            ->where('pi.is_current', true)->where('pi.status', 'confirmed')
            ->whereRaw('(pi.confirmed_at AT TIME ZONE ?)::date BETWEEN ? AND ?', [$tz, $from, $to])
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->where('c.account_manager_id', $user->id))
            ->selectRaw('pi.id AS pi_id, pi.pi_number, pi.currency, pi.client_id, c.name AS client, c.account_manager_id AS manager_id, u.name AS manager,
                (pi.confirmed_at AT TIME ZONE ?)::date AS day,
                (SELECT COALESCE(SUM(amount), 0) FROM pi_lines WHERE proforma_invoice_id = pi.id) AS amount', [$tz])
            ->get();

        return $this->convert($rows, 'amount', 'currency', 'day');
    }

    /** @return list<array{month: string, total: string, count: int}> every month of the range, empty ones too */
    public function salesByMonth(Collection $sales, string $from, string $to): array
    {
        $byMonth = $sales->groupBy('month');
        $out = [];
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $key = $m->format('Y-m');
            $group = $byMonth->get($key, collect());
            $out[] = ['month' => $key, 'total' => Decimal::sum($group->pluck('book')), 'count' => $group->count()];
        }

        return $out;
    }

    /** @return list<array{label: string, total: string, count: int, id: ?int}> the top $limit by value, the rest as "Other" */
    public function top(Collection $rows, string $key, string $label, int $limit = 8): array
    {
        $groups = $rows->groupBy($key)->map(fn (Collection $g) => [
            'id' => $g->first()->{$key}, 'label' => (string) ($g->first()->{$label} ?? '—'),
            'total' => Decimal::sum($g->pluck('book')), 'count' => $g->count(),
        ])->sortByDesc(fn ($g) => (float) $g['total'])->values();

        $top = $groups->take($limit)->all();
        $rest = $groups->slice($limit);
        if ($rest->isNotEmpty()) {
            $top[] = ['id' => null, 'label' => __('Other (:count)', ['count' => $rest->count()]), 'total' => Decimal::sum($rest->pluck('total')), 'count' => (int) $rest->sum('count')];
        }

        return $top;
    }

    /** Sales lines of confirmed PIs in the range, per product, in the book currency. */
    public function salesByProduct(string $from, string $to, User $user, int $limit = 8): array
    {
        $tz = config('app.timezone');
        $rows = DB::table('pi_lines as l')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'l.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('pi.is_current', true)->where('pi.status', 'confirmed')
            ->whereRaw('(pi.confirmed_at AT TIME ZONE ?)::date BETWEEN ? AND ?', [$tz, $from, $to])
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->where('c.account_manager_id', $user->id))
            ->selectRaw('l.product_id, p.name AS product, pi.currency, (pi.confirmed_at AT TIME ZONE ?)::date AS day, l.amount', [$tz])
            ->get();

        return $this->top($this->convert($rows, 'amount', 'currency', 'day'), 'product_id', 'product', $limit);
    }

    /**
     * Supplier orders confirmed in the range, per factory and per month.
     *
     * @return Collection<int, object> order_id, supplier_id, supplier, month, book
     */
    public function purchases(string $from, string $to): Collection
    {
        $rows = DB::table('supplier_orders as so')
            ->join('parties as s', 's.id', '=', 'so.supplier_id')
            ->where('so.status', '<>', 'cancelled')
            ->whereRaw('COALESCE(so.confirmation_date, so.created_at::date) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('so.id AS order_id, so.supplier_id, s.name AS supplier, so.currency, COALESCE(so.confirmation_date, so.created_at::date) AS day,
                (SELECT COALESCE(SUM(amount), 0) FROM supplier_order_lines WHERE supplier_order_id = so.id AND released_at IS NULL) AS amount')
            ->get();

        return $this->convert($rows, 'amount', 'currency', 'day');
    }

    /** Quotations dated in the range: how many were sent, won, lost, expired, and became a PI. */
    public function quotationFunnel(string $from, string $to, User $user): array
    {
        $today = now()->toDateString();
        $row = DB::table('quotations as q')
            ->join('parties as c', 'c.id', '=', 'q.client_id')
            ->whereBetween('q.quoted_on', [$from, $to])
            ->when(Visibility::ownClientsOnly($user), fn ($q) => $q->where('c.account_manager_id', $user->id))
            ->selectRaw("COUNT(*) AS total,
                COUNT(*) FILTER (WHERE q.status <> 'draft') AS sent,
                COUNT(*) FILTER (WHERE q.status = 'accepted') AS accepted,
                COUNT(*) FILTER (WHERE q.status = 'declined') AS declined,
                COUNT(*) FILTER (WHERE q.status = 'sent' AND q.valid_until < ?) AS expired,
                COUNT(*) FILTER (WHERE q.status = 'sent' AND q.valid_until >= ?) AS waiting,
                COUNT(*) FILTER (WHERE q.proforma_invoice_id IS NOT NULL) AS converted", [$today, $today])
            ->first();
        $decided = (int) $row->accepted + (int) $row->declined + (int) $row->expired;

        return [
            'total' => (int) $row->total, 'sent' => (int) $row->sent, 'accepted' => (int) $row->accepted,
            'declined' => (int) $row->declined, 'expired' => (int) $row->expired, 'waiting' => (int) $row->waiting,
            'converted' => (int) $row->converted,
            'win_rate' => $decided > 0 ? round((int) $row->accepted / $decided * 100, 1) : null,
        ];
    }

    /** Each row gains `book` (its amount in the book currency) and `month`; rows without a rate are dropped and counted. */
    private function convert(Collection $rows, string $amount, string $currency, string $day): Collection
    {
        return $rows->map(function ($row) use ($amount, $currency, $day) {
            $date = substr((string) $row->{$day}, 0, 10);
            try {
                $rate = $this->rates[$row->{$currency}.'|'.$date] ??= $this->ledger->rateOn($row->{$currency}, $date);
            } catch (AccountingException) {
                $this->missingRates[$row->{$currency}] = ($this->missingRates[$row->{$currency}] ?? 0) + 1;

                return null;
            }
            $row->book = Decimal::mul((string) $row->{$amount}, $rate);
            $row->month = substr($date, 0, 7);

            return $row;
        })->filter()->values();
    }
}
