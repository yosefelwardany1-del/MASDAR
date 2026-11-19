<?php

namespace App\Services\Finance;

use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use App\Support\Database\Refusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of bank_statements, bank_statement_lines and bank_matches (spec §5.8). A
 * statement is imported from the bank's CSV for one money account: its lines never change (a
 * wrong import is deleted while nothing on it is matched, then imported again). Each line is
 * matched to the payment it is — same account, same signed amount (trigger) — by hand or from
 * the suggestions: a payment that counts, not yet matched, of that amount, within a week.
 */
class BankReconciliationService
{
    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'Y/m/d', 'd-m-Y'];

    public function __construct(private PaymentQueries $payments) {}

    /**
     * @param  list<list<string>>  $rows  the CSV rows after the header
     * @param  array{date: int, description?: ?int, reference?: ?int, amount?: ?int, money_in?: ?int, money_out?: ?int, balance?: ?int, date_format: string}  $map  column indexes
     */
    public function import(MoneyAccount $account, array $rows, array $map, User $by, ?string $fileName = null, ?string $opening = null, ?string $closing = null): BankStatement
    {
        $this->authorize($by);
        $format = in_array($map['date_format'] ?? '', self::DATE_FORMATS, true) ? $map['date_format'] : 'Y-m-d';
        $lines = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $n = $i + 2;
            $date = $this->date($row[$map['date']] ?? '', $format);
            if ($date === null) {
                $errors[] = __('Row :n: the date :value is not :format.', ['n' => $n, 'value' => $row[$map['date']] ?? '', 'format' => $format]);

                continue;
            }
            $amount = isset($map['amount']) && $map['amount'] !== null
                ? $this->number($row[$map['amount']] ?? '')
                : bcsub($this->number($row[$map['money_in'] ?? -1] ?? '') ?? '0', $this->number($row[$map['money_out'] ?? -1] ?? '') ?? '0', 4);
            if ($amount === null) {
                $errors[] = __('Row :n: the amount is not a number.', ['n' => $n]);

                continue;
            }
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }
            $lines[] = [
                'booked_on' => $date,
                'description' => isset($map['description']) ? mb_substr(trim((string) ($row[$map['description']] ?? '')), 0, 2000) ?: null : null,
                'reference' => isset($map['reference']) ? mb_substr(trim((string) ($row[$map['reference']] ?? '')), 0, 120) ?: null : null,
                'amount' => $amount,
                'running_amount' => isset($map['balance']) ? $this->number($row[$map['balance']] ?? '') : null,
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 10)]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => __('The file has no lines with an amount.')]);
        }

        return $this->write(function () use ($account, $lines, $by, $fileName, $opening, $closing) {
            $dates = array_column($lines, 'booked_on');
            $statement = BankStatement::create([
                'money_account_id' => $account->id, 'period_from' => min($dates), 'period_to' => max($dates),
                'opening_amount' => $opening, 'closing_amount' => $closing ?? (end($lines)['running_amount'] ?? null),
                'file_name' => $fileName !== null ? mb_substr($fileName, 0, 200) : null, 'imported_by' => $by->id,
            ]);
            foreach ($lines as $i => $line) {
                BankStatementLine::create($line + ['bank_statement_id' => $statement->id, 'line_no' => $i + 1]);
            }
            AuditTrail::record('imported', $statement, [], ['account' => $account->name, 'lines' => count($lines), 'from' => $statement->period_from->toDateString(), 'to' => $statement->period_to->toDateString()]);

            return $statement;
        });
    }

    public function deleteStatement(BankStatement $statement, User $by): void
    {
        $this->authorize($by);
        $this->write(function () use ($statement) {
            $statement = BankStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if (BankMatch::query()->whereIn('bank_statement_line_id', $statement->lines()->select('id'))->exists()) {
                throw ValidationException::withMessages(['statement' => __('Lines of this statement are matched: unmatch them before deleting it.')]);
            }
            $old = $statement->only(['money_account_id', 'period_from', 'period_to', 'file_name']);
            $statement->delete();
            AuditTrail::record('deleted', $statement, $old, []);
        });
    }

    public function match(BankStatementLine $line, Payment $payment, User $by): BankMatch
    {
        $this->authorize($by);
        if (! $this->payments->isEffective($payment)) {
            throw ValidationException::withMessages(['payment_id' => __('Payment :number does not count yet (awaiting approval).', ['number' => $payment->number])]);
        }
        // B10: the trigger's rule, said first.
        if ((int) $payment->money_account_id !== (int) $line->statement->money_account_id) {
            throw ValidationException::withMessages(['payment_id' => __('This payment is in another money account than the statement.')]);
        }
        if (bccomp((string) $payment->signed_amount, (string) $line->amount, 4) !== 0) {
            throw ValidationException::withMessages(['payment_id' => __('This payment\'s amount is different from the statement line.')]);
        }

        return $this->write(function () use ($line, $payment, $by) {
            $match = BankMatch::create(['bank_statement_line_id' => $line->id, 'payment_id' => $payment->id, 'matched_by' => $by->id]);
            AuditTrail::record('matched', $line->statement, [], ['line' => $line->line_no, 'payment' => $payment->number, 'amount' => (string) $line->amount]);

            return $match;
        });
    }

    public function unmatch(BankStatementLine $line, User $by): void
    {
        $this->authorize($by);
        $this->write(function () use ($line) {
            $match = BankMatch::query()->where('bank_statement_line_id', $line->id)->with('payment')->first();
            if ($match) {
                $match->delete();
                AuditTrail::record('unmatched', $line->statement, ['line' => $line->line_no, 'payment' => $match->payment?->number], []);
            }
        });
    }

    /** Match every unmatched line of the statement that has exactly one candidate. @return int matched */
    public function matchSuggested(BankStatement $statement, User $by): int
    {
        $this->authorize($by);
        $count = 0;
        foreach ($this->suggestions($statement) as $lineId => $candidates) {
            if ($candidates->count() === 1) {
                $this->match(BankStatementLine::query()->findOrFail($lineId), $candidates->first(), $by);
                $count++;
            }
        }

        return $count;
    }

    /** @return Collection<int, Collection<int, Payment>> line id => candidate payments (unmatched, counting, same account and signed amount, ±7 days) */
    public function suggestions(BankStatement $statement): Collection
    {
        $lines = $statement->lines()->whereDoesntHave('match')->get();
        $matched = BankMatch::query()->select('payment_id');
        $taken = [];

        return $lines->mapWithKeys(function (BankStatementLine $line) use ($statement, $matched, &$taken) {
            $candidates = $this->payments->whereEffective(Payment::query())
                ->where('money_account_id', $statement->money_account_id)
                ->where('signed_amount', (string) $line->amount)
                ->whereBetween('paid_on', [$line->booked_on->copy()->subDays(7)->toDateString(), $line->booked_on->copy()->addDays(7)->toDateString()])
                ->whereNotIn('id', $matched)
                ->orderByRaw('ABS(paid_on - ?::date)', [$line->booked_on->toDateString()])
                ->get()
                ->reject(fn (Payment $p) => in_array($p->id, $taken, true))->values();
            if ($candidates->count() === 1) {
                $taken[] = $candidates->first()->id;
            }

            return [$line->id => $candidates];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function date(string $value, string $format): ?string
    {
        $date = \DateTime::createFromFormat('!'.$format, trim($value));
        $problems = \DateTime::getLastErrors();
        if (! $date || ($problems && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /** "1,234.50", "-120", "(120.00)" → a decimal string; "" → null. */
    private function number(string $value): ?string
    {
        $value = trim(str_replace([',', ' ', "\u{00A0}"], '', $value));
        if ($value === '') {
            return null;
        }
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');
        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return bcadd(($negative ? '-' : '').ltrim($value, '+'), '0', 4);
    }

    private function authorize(User $by): void
    {
        if (! $by->can('bank_reconciliation.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23001', 'P0001', '23505'], true)) {
                throw ValidationException::withMessages(['statement' => $e->getCode() === '23505'
                    ? __('That line or that payment is already matched.')
                    : Refusal::message($e)]);
            }
            throw $e;
        }
    }
}
