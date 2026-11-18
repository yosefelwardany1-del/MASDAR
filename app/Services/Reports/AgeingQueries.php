<?php

namespace App\Services\Reports;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\PostingRule;
use App\Models\User;
use App\Services\Accounting\Decimal;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receivables and payables by age (spec §5.8), from posted journal lines — what the books say
 * is owed, per party and currency, in that currency. Payments and credits settle the oldest
 * invoices first; what is left of each invoice is aged by its entry date into the buckets of
 * the ageing_buckets setting (30, 60, 90 to start). More paid than invoiced is an advance.
 * Read-only.
 *
 * The accounts are the posting rules' own: receivables are client_invoice's debit (and the
 * accounts client receipts and credit notes credit); payables are the supplier and shipping
 * bills' credit (and the accounts their payments and debit notes debit).
 */
class AgeingQueries
{
    public const SIDES = ['receivable', 'payable'];

    private const RULES = [
        'receivable' => [['client_invoice', 'debit'], ['client_receipt', 'credit'], ['credit_note', 'credit']],
        'payable' => [['supplier_bill', 'credit'], ['shipping_bill', 'credit'], ['supplier_payment', 'debit'], ['shipping_payment', 'debit'], ['debit_note', 'debit']],
    ];

    public function __construct(private Ledger $ledger) {}

    /** @return list<int> */
    public function accounts(string $side): array
    {
        $ids = [];
        foreach (self::RULES[$side] ?? [] as [$event, $column]) {
            $rule = PostingRule::query()->where('event', $event)->first();
            if ($id = $rule?->{$column.'_account_id'}) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> the bucket limits, e.g. [30, 60, 90] */
    public function buckets(): array
    {
        return BusinessSetting::current()->ageingBuckets();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, array<string, mixed>>, buckets: list<int>, hidden: int}
     */
    public function report(string $side, string $asAt, User $viewer): array
    {
        $buckets = $this->buckets();
        $accounts = $this->accounts($side);
        $empty = ['rows' => [], 'totals' => [], 'buckets' => $buckets, 'hidden' => 0];
        if ($accounts === []) {
            return $empty;
        }

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->where('e.entry_date', '<=', $asAt)
            ->whereIn('l.account_id', $accounts)->whereNotNull('l.party_id')
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')
            ->get(['l.party_id', 'l.currency', 'l.debit', 'l.amount_currency', 'e.entry_date', 'e.number']);

        $parties = Party::query()->whereIn('id', $lines->pluck('party_id')->unique())->get()->keyBy('id');
        $asAtDate = Carbon::parse($asAt)->startOfDay();
        $rows = [];
        $hidden = 0;

        foreach ($lines->groupBy(fn ($l) => $l->party_id.'|'.$l->currency) as $group) {
            $party = $parties->get($group->first()->party_id);
            if (! $this->ledger->canSeeParty($viewer, $party)) {
                $hidden++;

                continue;
            }
            $row = $this->age($group, $side, $asAtDate, $buckets);
            if (Decimal::isZero($row['open']) && Decimal::isZero($row['advance'])) {
                continue;
            }
            $rows[] = ['party' => $party, 'currency' => $group->first()->currency] + $row;
        }

        usort($rows, fn ($a, $b) => [$a['currency'], -(float) $a['open'], $a['party']->name] <=> [$b['currency'], -(float) $b['open'], $b['party']->name]);

        $totals = [];
        foreach ($rows as $row) {
            $t = $totals[$row['currency']] ?? ['open' => '0', 'advance' => '0', 'buckets' => array_fill(0, count($buckets) + 1, '0')];
            $t['open'] = Decimal::add($t['open'], $row['open']);
            $t['advance'] = Decimal::add($t['advance'], $row['advance']);
            foreach ($row['buckets'] as $i => $amount) {
                $t['buckets'][$i] = Decimal::add($t['buckets'][$i], $amount);
            }
            $totals[$row['currency']] = $t;
        }
        ksort($totals);

        return ['rows' => $rows, 'totals' => $totals, 'buckets' => $buckets, 'hidden' => $hidden];
    }

    /**
     * First in, first out: the decreases (payments, credits) settle the oldest increases
     * (invoices, bills); what remains of each is put in the bucket of its age.
     */
    private function age($lines, string $side, Carbon $asAt, array $buckets): array
    {
        $items = [];
        $settled = '0';
        foreach ($lines as $line) {
            $isDebit = Decimal::isPositive((string) $line->debit);
            $increases = $side === 'receivable' ? $isDebit : ! $isDebit;
            if ($increases) {
                $items[] = ['date' => (string) $line->entry_date, 'amount' => Decimal::normalize((string) $line->amount_currency), 'number' => $line->number];
            } else {
                $settled = Decimal::add($settled, (string) $line->amount_currency);
            }
        }

        $amounts = array_fill(0, count($buckets) + 1, '0');
        $open = '0';
        $oldest = null;
        foreach ($items as $item) {
            $take = Decimal::cmp($settled, $item['amount']) >= 0 ? $item['amount'] : $settled;
            $settled = Decimal::sub($settled, $take);
            $left = Decimal::sub($item['amount'], $take);
            if (! Decimal::isPositive($left)) {
                continue;
            }
            $days = (int) Carbon::parse($item['date'])->startOfDay()->diffInDays($asAt, false);
            $index = count($buckets);
            foreach ($buckets as $i => $limit) {
                if ($days <= $limit) {
                    $index = $i;
                    break;
                }
            }
            $amounts[$index] = Decimal::add($amounts[$index], $left);
            $open = Decimal::add($open, $left);
            $oldest ??= ['date' => $item['date'], 'days' => $days];
        }

        return ['open' => $open, 'advance' => $settled, 'buckets' => $amounts, 'oldest' => $oldest];
    }
}
