<?php

namespace App\Services\Accounting;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * The chart as an ordered, indented list: each heading followed by its sub-accounts (by code),
 * each row with its own movement (debit − credit) and headings summing their children. The
 * display amount is sign-flipped for credit-natured accounts (liability, equity, income), so a
 * normal balance shows positive. Computed on every read; nothing is stored.
 */
final class ChartTree
{
    /**
     * @param  Collection<int, Account>  $accounts  the accounts to show (any subset closed under parents)
     * @param  array<int, string>  $raw  account_id => SUM(debit − credit)
     * @return list<array{account: Account, depth: int, raw: string, amount: string}>
     */
    public static function rows(Collection $accounts, array $raw): array
    {
        $accounts = $accounts->keyBy('id');
        $byParent = $accounts->sortBy('code', SORT_NATURAL)->groupBy(fn (Account $a) => $a->parent_id && $accounts->has($a->parent_id) ? $a->parent_id : 0);
        $rows = [];
        self::walk($byParent, 0, 0, $raw, $rows);

        return $rows;
    }

    /** Total of the display amounts of the top-level rows. */
    public static function total(array $rows): string
    {
        return Decimal::sum(array_map(fn ($r) => $r['amount'], array_filter($rows, fn ($r) => $r['depth'] === 0)));
    }

    /** @return string the subtree's raw total */
    private static function walk(Collection $byParent, int $parent, int $depth, array $raw, array &$rows): string
    {
        $total = '0';
        foreach ($byParent->get($parent, collect()) as $account) {
            $index = count($rows);
            $rows[] = ['account' => $account, 'depth' => $depth, 'raw' => '0', 'amount' => '0'];
            $own = $raw[$account->id] ?? '0';
            $sum = Decimal::add($own, self::walk($byParent, $account->id, $depth + 1, $raw, $rows));
            $rows[$index]['raw'] = $sum;
            $rows[$index]['amount'] = $account->isDebitNatured() ? $sum : Decimal::neg($sum);
            $total = Decimal::add($total, $sum);
        }

        return $total;
    }
}
