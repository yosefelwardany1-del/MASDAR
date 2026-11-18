<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\PostingRule;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Opening balances brought in from a file (spec §5.8, §9): one row per balance — date, account
 * code, party code (for a client's or supplier's open item, dated as the invoice so ageing
 * works), currency, amount (+ debit, − credit), rate (for another currency than the book's,
 * else the rate of that date), memo. Each date becomes one DRAFT manual entry through
 * PostingService (event opening_balance), balanced against the opening_balance rule's account
 * (3901 Opening balance equity). A manager checks and posts the drafts on the Journal screen.
 */
class OpeningBalanceService
{
    public const COLUMNS = ['date', 'account', 'party', 'currency', 'amount', 'rate', 'memo'];

    public function __construct(private PostingService $posting, private Ledger $ledger) {}

    /**
     * @param  list<array<string, string>>  $rows  keyed by COLUMNS
     * @return list<JournalEntry> the drafts made
     */
    public function import(array $rows, User $by): array
    {
        $book = $this->ledger->bookCurrency();
        $rule = PostingRule::query()->where('event', 'opening_balance')->with('creditAccount')->first();
        $equity = $rule?->creditAccount ?? throw ValidationException::withMessages(['file' => __('Set the credit account of posting rule :event on the Accounting screen.', ['event' => 'opening_balance'])]);
        $accounts = Account::query()->get()->keyBy('code');
        $parties = Party::query()->get(['id', 'code'])->keyBy('code');
        $errors = [];
        $byDate = [];

        foreach ($rows as $i => $row) {
            $n = $i + 2;
            $date = trim($row['date'] ?? '');
            $account = $accounts->get(trim($row['account'] ?? ''));
            $amount = str_replace([',', ' '], '', trim($row['amount'] ?? ''));
            $currency = strtoupper(trim($row['currency'] ?? '')) ?: $book;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = __('Row :n: the date is YYYY-MM-DD.', ['n' => $n]);
            }
            if (! $account || ! $account->is_postable) {
                $errors[] = __('Row :n: :code is not an account that takes postings.', ['n' => $n, 'code' => $row['account'] ?? '']);
            }
            if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $amount) || bccomp($amount, '0', 4) === 0) {
                $errors[] = __('Row :n: the amount is a number, + for a debit and − for a credit.', ['n' => $n]);
            }
            $party = null;
            if (filled($row['party'] ?? null)) {
                $party = $parties->get(trim($row['party']));
                if (! $party) {
                    $errors[] = __('Row :n: there is no party :code.', ['n' => $n, 'code' => $row['party']]);
                }
            }
            if ($errors !== [] && count($errors) > 20) {
                break;
            }
            if (! $account || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^-?\d+(\.\d{1,4})?$/', $amount)) {
                continue;
            }
            $rate = filled($row['rate'] ?? null) ? trim($row['rate']) : null;
            if ($currency !== $book && $rate === null) {
                try {
                    $rate = $this->ledger->rateOn($currency, $date);
                } catch (AccountingException $e) {
                    $errors[] = __('Row :n: :message', ['n' => $n, 'message' => $e->getMessage()]);

                    continue;
                }
            }
            $negative = str_starts_with($amount, '-');
            $abs = ltrim($amount, '-');
            $byDate[$date][] = [
                'account_id' => $account->id, 'party_id' => $party?->id, 'currency' => $currency, 'rate' => $rate,
                'debit' => $negative ? null : $abs, 'credit' => $negative ? $abs : null,
                'memo' => filled($row['memo'] ?? null) ? mb_substr(trim($row['memo']), 0, 255) : null,
                'book' => $currency === $book ? $abs : Decimal::mul($abs, (string) $rate), 'negative' => $negative,
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 15)]);
        }
        if ($byDate === []) {
            throw ValidationException::withMessages(['file' => __('The file has no balances.')]);
        }

        ksort($byDate);
        $drafts = [];
        foreach ($byDate as $date => $lines) {
            $net = '0';
            foreach ($lines as $line) {
                $net = $line['negative'] ? Decimal::sub($net, $line['book']) : Decimal::add($net, $line['book']);
            }
            if (! Decimal::isZero($net)) {
                $lines[] = ['account_id' => $equity->id, 'party_id' => null, 'currency' => $book, 'rate' => null, 'memo' => 'Opening balance',
                    'debit' => Decimal::isPositive($net) ? null : Decimal::abs($net), 'credit' => Decimal::isPositive($net) ? $net : null];
            }
            $drafts[] = $this->posting->createDraft([
                'entry_date' => $date, 'event' => 'opening_balance',
                'description' => __('Opening balances at :date', ['date' => $date], PostingService::STORED_LOCALE),
                'lines' => array_map(fn ($l) => array_diff_key($l, ['book' => 1, 'negative' => 1]), $lines),
            ], $by);
        }

        return $drafts;
    }
}
