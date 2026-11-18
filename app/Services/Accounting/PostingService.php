<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Container;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\PostingRule;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Hr\PayrollQueries;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY writer of journal_entries and journal_lines (spec §3.6). Every entry is inserted as
 * a draft, its lines inserted, then — when posted — updated to `posted`; PostgreSQL refuses a
 * post that does not balance (I1) or falls in a closed period (I3), and never lets a posted
 * entry or line change (I2). The checks here exist only to give a person a message they can
 * act on; the triggers are the gate.
 *
 * Manual entries (adjustments) are drafted with `accounting.edit` and posted or reversed only
 * with `accounting.approve` (spec §6: "an adjustment journal entry" needs a manager). Documents
 * and payments are posted by rule from the "To post" queue with `accounting.edit`. A posted
 * entry takes its number from the journal series when it is posted, so posted numbers are
 * gapless; a draft carries a provisional "DRAFT-…" number until then.
 */
class PostingService
{
    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_PI = 'proforma_invoice';

    public const SOURCE_SUPPLIER_ORDER = 'supplier_order';

    public const SOURCE_CONTAINER = 'container';

    public const SOURCE_PAYROLL = 'payroll_run';

    /**
     * The sides of each posting rule that the rule sets. For payments the other side is the
     * money account's own ledger account; events not listed here set both sides.
     */
    public const RULE_SIDES = [
        'client_invoice' => ['debit', 'credit'],
        'supplier_bill' => ['debit', 'credit'],
        'shipping_bill' => ['debit', 'credit'],
        'client_receipt' => ['credit'],
        'supplier_payment' => ['debit'],
        'shipping_payment' => ['debit'],
        'transfer_out' => ['debit'],
        'transfer_in' => ['credit'],
        'fx_difference' => ['debit', 'credit'],
        'payroll' => ['debit', 'credit'],
        'payroll_contributions' => ['debit', 'credit'],
        'payroll_tax' => ['credit'],
        'payroll_deductions' => ['credit'],
        'salary_payment' => ['debit'],
    ];

    /** English names of the posting events, shown through __(). */
    public const EVENT_LABELS = [
        'client_invoice' => 'Client invoice',
        'supplier_bill' => 'Supplier bill',
        'shipping_bill' => 'Shipping bill',
        'client_receipt' => 'Client receipt',
        'supplier_payment' => 'Supplier payment',
        'shipping_payment' => 'Shipping company payment',
        'expense' => 'Expense',
        'other_in' => 'Other money in',
        'other_out' => 'Other money out',
        'transfer_out' => 'Transfer out',
        'transfer_in' => 'Transfer in',
        'fx_difference' => 'Exchange difference',
        'payroll' => 'Payroll',
        'payroll_contributions' => 'Social insurance and housing fund',
        'payroll_tax' => 'Income tax withheld',
        'payroll_deductions' => 'Other salary deductions',
        'salary_payment' => 'Salary payment',
    ];

    public function __construct(private NumberingService $numbers, private Ledger $ledger) {}

    /**
     * Descriptions the posting service writes are stored in English, whoever posts: the books
     * are a Hong Kong company's, and a stored text must not depend on the poster's language.
     */
    public const STORED_LOCALE = 'en';

    public static function eventLabel(?string $event, ?string $locale = null): string
    {
        return $event === null ? __('Manual entry', [], $locale) : (isset(self::EVENT_LABELS[$event]) ? __(self::EVENT_LABELS[$event], [], $locale) : $event);
    }

    // ---------------------------------------------------------------- manual entries

    /**
     * @param  array{entry_date: string, description?: ?string, lines: list<array<string, mixed>>}  $data
     *                                                                                                     Each line: account_id, party_id?, memo?, currency?, debit?, credit?, rate? —
     *                                                                                                     debit or credit in the line's currency (the book currency when none is given).
     */
    public function createDraft(array $data, User $by): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $lines = $this->manualLines($data['lines'] ?? []);
        $this->assertOpen($data['entry_date']);

        return $this->write(function () use ($data, $lines, $by) {
            $entry = JournalEntry::create([
                'number' => $this->provisionalNumber(),
                'entry_date' => $data['entry_date'],
                'status' => 'draft',
                'description' => $data['description'] ?? null,
                'created_by' => $by->id,
            ]);
            $this->insertLines($entry, $lines);
            AuditTrail::record('created', $entry, [], $this->snapshot($entry));

            return $entry;
        });
    }

    public function updateDraft(JournalEntry $entry, array $data, User $by): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $this->assertDraft($entry);
        $lines = $this->manualLines($data['lines'] ?? []);
        $this->assertOpen($data['entry_date']);

        return $this->write(function () use ($entry, $data, $lines) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->assertDraft($entry);
            $old = $this->snapshot($entry);

            JournalLine::query()->where('journal_entry_id', $entry->id)->delete();
            $entry->update(['entry_date' => $data['entry_date'], 'description' => $data['description'] ?? null]);
            $this->insertLines($entry, $lines);
            AuditTrail::record('updated', $entry, $old, $this->snapshot($entry));

            return $entry;
        });
    }

    public function deleteDraft(JournalEntry $entry, User $by): void
    {
        $this->authorize($by, 'accounting.edit');
        $this->assertDraft($entry);
        if ($entry->attachments()->exists()) {
            throw new AccountingException(__('The draft has attachments: remove them first.'));
        }

        $this->write(function () use ($entry) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->assertDraft($entry);
            $old = $this->snapshot($entry);

            JournalLine::query()->where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
            AuditTrail::record('deleted', $entry, $old, []);
        });
    }

    /** Post a manual draft: it must balance and be dated in an open period. */
    public function post(JournalEntry $entry, User $by): JournalEntry
    {
        $this->authorize($by, 'accounting.approve');
        $this->assertDraft($entry);

        return $this->write(function () use ($entry, $by) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->assertDraft($entry);
            $lines = $entry->lines()->with('account')->get();
            foreach ($lines as $line) {
                $this->assertPostable($line->account);
            }
            $this->assertBalanced($lines->map(fn (JournalLine $l) => ['debit' => (string) $l->debit, 'credit' => (string) $l->credit])->all());
            $this->assertOpen($entry->entry_date->toDateString());

            $this->markPosted($entry, $by);
            AuditTrail::record('posted', $entry, ['status' => 'draft'], $this->snapshot($entry));

            return $entry;
        });
    }

    /**
     * Reverse a posted entry: a new posted entry with debits and credits swapped and
     * reverses_entry_id set. A payment's entry is reversed by recording a reversal of the
     * payment (its posting swaps the lines), so the ledger and the payments always agree.
     */
    public function reverse(JournalEntry $entry, User $by, ?string $on = null, ?string $description = null): JournalEntry
    {
        $this->authorize($by, 'accounting.approve');
        $entry = JournalEntry::query()->with('lines', 'reversal')->findOrFail($entry->id);

        if (! $entry->isPosted()) {
            throw new AccountingException(__('Only a posted entry is reversed; edit or delete a draft instead.'));
        }
        if ($entry->source_type === self::SOURCE_PAYMENT) {
            throw new AccountingException(__('This entry comes from a payment. Record a reversal of the payment on the Payments screen; posting it reverses this entry.'));
        }

        return $this->writeReversal($entry, $by, $on ?? now()->toDateString(), $description);
    }

    // ---------------------------------------------------------------- automatic postings

    /**
     * Post an effective payment (phase-1 contract). The money side is the money account's
     * ledger account; the other side is the posting rule of the payment's kind, or the
     * payment's counter account for expense / other_in / other_out. Every line keeps the
     * payment's currency, amount and rate; the book side is its book_amount. A reversal
     * payment posts the original payment's entry with its lines swapped.
     */
    public function postPayment(Payment $payment, User $by): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $payment = Payment::query()->with('moneyAccount.ledgerAccount', 'counterAccount', 'reverses')->findOrFail($payment->id);

        if (! $this->ledger->isEffective($payment)) {
            throw new AccountingException(__('Payment :number is awaiting approval; it is posted once approved.', ['number' => $payment->number]));
        }
        if ($posted = $this->anyPostingOf($payment)) {
            throw new AccountingException(__('Payment :number is already posted (:entry).', ['number' => $payment->number, 'entry' => $posted->number]));
        }

        $header = [
            'entry_date' => $payment->paid_on->toDateString(),
            'event' => $payment->kind,
            'source_type' => self::SOURCE_PAYMENT,
            'source_id' => $payment->id,
            'description' => $this->stored('Payment :number', ['number' => $payment->number]).' — '.self::eventLabel($payment->kind, self::STORED_LOCALE)
                .($payment->reverses ? ' — '.$this->stored('reverses :original', ['original' => $payment->reverses->number]) : '')
                .($payment->reference ? ' · '.$payment->reference : ''),
        ];

        if ($payment->reverses_payment_id) {
            $original = $this->ledger->postingOf(self::SOURCE_PAYMENT, $payment->reverses_payment_id);
            if (! $original) {
                throw new AccountingException(__('Payment :number reverses :original, which is not posted yet. Post :original first.', [
                    'number' => $payment->number, 'original' => $payment->reverses?->number,
                ]));
            }

            return $this->writeReversal($original, $by, $header['entry_date'], $header['description'], $header);
        }

        return $this->postNew($header, $this->paymentLines($payment), $by);
    }

    /**
     * Invoice a confirmed, current PI to its client (event client_invoice): Dr the rule's debit
     * (1401, party = client) / Cr the rule's credit, for the SUM of its lines in the PI's
     * currency at the rate on the invoice date — the PI's confirmation date unless $on is given.
     */
    public function postClientInvoice(ProformaInvoice $pi, User $by, ?string $on = null): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $pi = ProformaInvoice::query()->with('client')->findOrFail($pi->id);

        if ($pi->status !== 'confirmed' || ! $pi->is_current) {
            throw new AccountingException(__('Only a confirmed, current PI is invoiced; :pi is not.', ['pi' => $pi->reference()]));
        }
        if ($posted = $this->ledger->postingOf(self::SOURCE_PI, $pi->id, 'client_invoice')) {
            throw new AccountingException(__(':pi is already invoiced (:entry).', ['pi' => $pi->reference(), 'entry' => $posted->number]));
        }
        if ($other = $this->unreversedInvoiceOfAnotherRevision($pi)) {
            throw new AccountingException(__(':pi was already invoiced from another revision (:entry). Reverse that entry first.', [
                'pi' => $pi->pi_number, 'entry' => $other->number,
            ]));
        }

        $total = (string) DB::table('pi_lines')->where('proforma_invoice_id', $pi->id)->selectRaw('COALESCE(SUM(amount), 0) AS total')->value('total');
        if (! Decimal::isPositive($total)) {
            throw new AccountingException(__(':pi has no amount to invoice.', ['pi' => $pi->reference()]));
        }

        $date = $on ?: ($pi->confirmed_at?->timezone(config('app.timezone'))->toDateString() ?? now()->toDateString());
        $rate = $this->ledger->rateOn($pi->currency, $date);
        $book = Decimal::mul($total, $rate);
        $memo = $pi->reference();

        return $this->postNew([
            'entry_date' => $date, 'event' => 'client_invoice',
            'source_type' => self::SOURCE_PI, 'source_id' => $pi->id,
            'description' => $this->stored('Invoice of :pi to :client', ['pi' => $pi->reference(), 'client' => $pi->client?->name]),
        ], [
            $this->line($this->ruleAccount('client_invoice', 'debit'), 'debit', $book, $pi->currency, $total, $rate, $pi->client_id, $memo),
            $this->line($this->ruleAccount('client_invoice', 'credit'), 'credit', $book, $pi->currency, $total, $rate, null, $memo),
        ], $by);
    }

    /**
     * Bill a fully received supplier order (event supplier_bill): Dr the rule's debit (5101) /
     * Cr the rule's credit (2201, party = supplier), for the SUM of its lines at the rate on the
     * bill date — the last receipt date unless $on is given.
     */
    public function postSupplierBill(SupplierOrder $order, User $by, ?string $on = null): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $order = SupplierOrder::query()->with('supplier')->findOrFail($order->id);

        if ($order->status === 'cancelled') {
            throw new AccountingException(__('Supplier order :number is cancelled; it is not billed.', ['number' => $order->number]));
        }
        if (! DB::table('supplier_order_lines')->where('supplier_order_id', $order->id)->exists()) {
            throw new AccountingException(__('Supplier order :number has no lines to bill.', ['number' => $order->number]));
        }
        if (! $this->isFullyReceived($order)) {
            throw new AccountingException(__('Supplier order :number is not fully received yet; it is billed once every line is received.', ['number' => $order->number]));
        }
        if ($posted = $this->ledger->postingOf(self::SOURCE_SUPPLIER_ORDER, $order->id, 'supplier_bill')) {
            throw new AccountingException(__('Supplier order :number is already billed (:entry).', ['number' => $order->number, 'entry' => $posted->number]));
        }

        $total = (string) DB::table('supplier_order_lines')->where('supplier_order_id', $order->id)->selectRaw('COALESCE(SUM(amount), 0) AS total')->value('total');
        if (! Decimal::isPositive($total)) {
            throw new AccountingException(__('Supplier order :number has no amount to bill.', ['number' => $order->number]));
        }

        $date = $on ?: ((string) DB::table('goods_receipts as g')->join('supplier_order_lines as l', 'l.id', '=', 'g.supplier_order_line_id')
            ->where('l.supplier_order_id', $order->id)->max('g.received_on') ?: now()->toDateString());
        $date = substr($date, 0, 10);
        $rate = $this->ledger->rateOn($order->currency, $date);
        $book = Decimal::mul($total, $rate);

        return $this->postNew([
            'entry_date' => $date, 'event' => 'supplier_bill',
            'source_type' => self::SOURCE_SUPPLIER_ORDER, 'source_id' => $order->id,
            'description' => $this->stored('Bill of supplier order :number from :supplier', ['number' => $order->number, 'supplier' => $order->supplier?->name]),
        ], [
            $this->line($this->ruleAccount('supplier_bill', 'debit'), 'debit', $book, $order->currency, $total, $rate, null, $order->number),
            $this->line($this->ruleAccount('supplier_bill', 'credit'), 'credit', $book, $order->currency, $total, $rate, $order->supplier_id, $order->number),
        ], $by);
    }

    /**
     * Book an approved payroll run (spec §5.7, event payroll and its companions), on the last day
     * of its month at that day's rate:
     *   Dr 6201 Salaries (gross)              Cr 2402 Salaries payable (net)
     *   Dr 6203 Employer contributions        Cr 2403 Social insurance and housing fund (both shares)
     *                                         Cr 2404 Income tax withheld
     *                                         Cr 1703 Other deductions
     * Each account comes from its posting rule. Salaries payable takes the rounding, so the
     * entry balances in the book currency to the last unit.
     */
    public function postPayroll(PayrollRun $run, User $by): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');
        $this->authorize($by, 'payroll.view');
        $run = PayrollRun::query()->findOrFail($run->id);
        if ($run->status !== 'approved') {
            throw new AccountingException(__('Only an approved payroll run is posted; :number is not.', ['number' => $run->number]));
        }
        if ($posted = $this->ledger->postingOf(self::SOURCE_PAYROLL, $run->id, 'payroll')) {
            throw new AccountingException(__('Payroll run :number is already posted (:entry).', ['number' => $run->number, 'entry' => $posted->number]));
        }

        $t = app(PayrollQueries::class)->totals($run);
        $date = $run->periodEnd();
        $rate = $run->currency === $this->ledger->bookCurrency() ? '1.000000' : $this->ledger->rateOn($run->currency, $date);
        $employer = Decimal::add($t['social_insurance_employer'], $t['housing_fund_employer']);
        $contributions = Decimal::sum([$t['social_insurance_employee'], $t['housing_fund_employee'], $employer]);
        $memo = $run->number;
        $book = fn (string $amount) => Decimal::mul($amount, $rate);

        $lines = [];
        $credits = '0';
        $add = function (string $event, string $side, string $amount) use (&$lines, &$credits, $book, $run, $rate, $memo) {
            if (! Decimal::isPositive($amount)) {
                return;
            }
            $lines[] = $this->line($this->ruleAccount($event, $side), $side, $book($amount), $run->currency, $amount, $rate, null, $memo);
            if ($side === 'credit') {
                $credits = Decimal::add($credits, $book($amount));
            }
        };
        $add('payroll', 'debit', $t['gross']);
        $add('payroll_contributions', 'debit', $employer);
        $add('payroll_contributions', 'credit', $contributions);
        $add('payroll_tax', 'credit', $t['income_tax']);
        $add('payroll_deductions', 'credit', $t['other_deductions']);
        if (Decimal::isPositive($t['net'])) {
            $debits = Decimal::add($book($t['gross']), Decimal::isPositive($employer) ? $book($employer) : '0');
            $lines[] = $this->line($this->ruleAccount('payroll', 'credit'), 'credit', Decimal::sub($debits, $credits), $run->currency, $t['net'], $rate, null, $memo);
        }
        if ($lines === []) {
            throw new AccountingException(__('Payroll run :number has no amount to post.', ['number' => $run->number]));
        }

        return $this->postNew([
            'entry_date' => $date, 'event' => 'payroll',
            'source_type' => self::SOURCE_PAYROLL, 'source_id' => $run->id,
            'description' => $this->stored('Payroll :number for :month', ['number' => $run->number, 'month' => $run->period->locale('en')->isoFormat('MMMM YYYY')]),
        ], $lines, $by);
    }

    /**
     * A shipping company's bill (event shipping_bill): Dr the rule's debit (5201) / Cr the
     * rule's credit (2301, party = the company). Its source is the container, when one is
     * named; a container takes one shipping bill (the one-posting-per-source index).
     */
    public function postShippingBill(Party $company, ?Container $container, string $amount, string $currency, string $date, ?string $description, User $by, ?string $rate = null): JournalEntry
    {
        $this->authorize($by, 'accounting.edit');

        if (! $company->is_shipping) {
            throw new AccountingException(__(':name is not a shipping company.', ['name' => $company->name]));
        }
        $amount = Decimal::normalize($amount);
        if (! Decimal::isPositive($amount)) {
            throw new AccountingException(__('A bill needs an amount greater than zero.'));
        }
        if ($container && ($posted = $this->ledger->postingOf(self::SOURCE_CONTAINER, $container->id, 'shipping_bill'))) {
            throw new AccountingException(__('Container :container already has a shipping bill (:entry). Post this bill without a container.', [
                'container' => $container->container_no ?: '#'.$container->id, 'entry' => $posted->number,
            ]));
        }

        $rate = $currency === $this->ledger->bookCurrency() ? '1.000000' : ($rate !== null && $rate !== '' ? Decimal::normalize($rate, 6) : $this->ledger->rateOn($currency, $date));
        if (! Decimal::isPositive($rate, 6)) {
            throw new AccountingException(__('The rate must be greater than zero.'));
        }
        $book = Decimal::mul($amount, $rate);
        $memo = $container ? ($container->container_no ?: '#'.$container->id) : null;

        return $this->postNew([
            'entry_date' => $date, 'event' => 'shipping_bill',
            'source_type' => $container ? self::SOURCE_CONTAINER : null, 'source_id' => $container?->id,
            'description' => $description ?: $this->stored('Shipping bill from :company', ['company' => $company->name]),
        ], [
            $this->line($this->ruleAccount('shipping_bill', 'debit'), 'debit', $book, $currency, $amount, $rate, null, $memo),
            $this->line($this->ruleAccount('shipping_bill', 'credit'), 'credit', $book, $currency, $amount, $rate, $company->id, $memo),
        ], $by);
    }

    /** Every line of the order has received cartons ≥ its ordered cartons. */
    public function isFullyReceived(SupplierOrder $order): bool
    {
        return ! DB::table('supplier_order_lines as l')
            ->where('l.supplier_order_id', $order->id)
            ->whereRaw('l.cartons > (SELECT COALESCE(SUM(g.cartons), 0) FROM goods_receipts g WHERE g.supplier_order_line_id = l.id)')
            ->exists();
    }

    // ---------------------------------------------------------------- building lines

    /** @return list<array<string, mixed>> */
    private function paymentLines(Payment $payment): array
    {
        $money = $payment->moneyAccount?->ledgerAccount;
        if (! $money) {
            throw new AccountingException(__('Set the ledger account of money account :name on the Payments screen.', ['name' => $payment->moneyAccount?->name]));
        }

        $book = (string) $payment->book_amount;
        if (! Decimal::isPositive($book)) {
            throw new AccountingException(__('Payment :number is too small to post in the book currency.', ['number' => $payment->number]));
        }
        $line = fn (Account $account, string $side, ?int $party = null) => $this->line(
            $account, $side, $book, $payment->currency, (string) $payment->amount, (string) $payment->rate, $party, $payment->number,
        );
        $counter = function () use ($payment): Account {
            if (! $payment->counterAccount) {
                throw new AccountingException(__('Payment :number has no counter account. Reverse it and record it again with one.', ['number' => $payment->number]));
            }

            return $payment->counterAccount;
        };

        return match ($payment->kind) {
            'client_receipt' => [$line($money, 'debit'), $line($this->ruleAccount('client_receipt', 'credit'), 'credit', $payment->party_id)],
            'supplier_payment' => [$line($this->ruleAccount('supplier_payment', 'debit'), 'debit', $payment->party_id), $line($money, 'credit')],
            'shipping_payment' => [$line($this->ruleAccount('shipping_payment', 'debit'), 'debit', $payment->party_id), $line($money, 'credit')],
            'expense', 'other_out' => [$line($counter(), 'debit', $payment->party_id), $line($money, 'credit')],
            'other_in' => [$line($money, 'debit'), $line($counter(), 'credit', $payment->party_id)],
            'transfer_out' => [$line($this->ruleAccount('transfer_out', 'debit'), 'debit'), $line($money, 'credit')],
            'transfer_in' => [$line($money, 'debit'), $line($this->ruleAccount('transfer_in', 'credit'), 'credit')],
            'salary_payment' => [$line($this->ruleAccount('salary_payment', 'debit'), 'debit'), $line($money, 'credit')],
            default => throw new AccountingException(__('Payments of kind :kind have no posting.', ['kind' => $payment->kind])),
        };
    }

    /** @return array<string, mixed> */
    private function line(Account $account, string $side, string $book, string $currency, string $amount, string $rate, ?int $party, ?string $memo): array
    {
        return [
            'account' => $account,
            'party_id' => $party,
            'debit' => $side === 'debit' ? $book : '0',
            'credit' => $side === 'credit' ? $book : '0',
            'currency' => $currency,
            'amount_currency' => $amount,
            'rate' => $rate,
            'memo' => $memo,
        ];
    }

    /**
     * Manual lines: a debit or a credit in the line's currency; in another currency than the
     * book's it takes a rate and its book amount is amount × rate, rounded to 4 places.
     *
     * @return list<array<string, mixed>>
     */
    private function manualLines(array $rows): array
    {
        if ($rows === []) {
            throw new AccountingException(__('An entry needs at least one line.'));
        }
        $book = $this->ledger->bookCurrency();
        $accounts = Account::query()->whereIn('id', array_column($rows, 'account_id'))->get()->keyBy('id');
        $lines = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $account = $accounts->get((int) ($row['account_id'] ?? 0));
            if (! $account) {
                throw new AccountingException(__('Line :n: choose an account.', ['n' => $n]));
            }
            $debit = Decimal::normalize(filled($row['debit'] ?? null) ? (string) $row['debit'] : '0');
            $credit = Decimal::normalize(filled($row['credit'] ?? null) ? (string) $row['credit'] : '0');
            if (Decimal::isPositive($debit) === Decimal::isPositive($credit)) {
                throw new AccountingException(__('Line :n: enter either a debit or a credit.', ['n' => $n]));
            }
            $currency = strtoupper((string) ($row['currency'] ?? '') ?: $book);
            if ($currency === $book) {
                $rate = '1.000000';
            } else {
                $rate = Decimal::normalize(filled($row['rate'] ?? null) ? (string) $row['rate'] : '0', 6);
                if (! Decimal::isPositive($rate, 6)) {
                    throw new AccountingException(__('Line :n: a line in :currency needs its rate to :book.', ['n' => $n, 'currency' => $currency, 'book' => $book]));
                }
            }
            $side = Decimal::isPositive($debit) ? 'debit' : 'credit';
            $amount = $side === 'debit' ? $debit : $credit;
            $bookAmount = Decimal::mul($amount, $rate);
            if (! Decimal::isPositive($bookAmount)) {
                throw new AccountingException(__('Line :n is too small to post in the book currency.', ['n' => $n]));
            }

            $lines[] = $this->line($account, $side, $bookAmount, $currency, $amount, $rate,
                filled($row['party_id'] ?? null) ? (int) $row['party_id'] : null,
                filled($row['memo'] ?? null) ? (string) $row['memo'] : null);
        }

        return $lines;
    }

    private function ruleAccount(string $event, string $side): Account
    {
        $rule = PostingRule::query()->with('debitAccount', 'creditAccount')->where('event', $event)->first();
        if (! $rule) {
            throw new AccountingException(__('Set up posting rule :event on the Accounting screen.', ['event' => $event]));
        }
        $account = $side === 'debit' ? $rule->debitAccount : $rule->creditAccount;
        if (! $account) {
            throw new AccountingException($side === 'debit'
                ? __('Set the debit account of posting rule :event on the Accounting screen.', ['event' => $event])
                : __('Set the credit account of posting rule :event on the Accounting screen.', ['event' => $event]));
        }

        return $account;
    }

    // ---------------------------------------------------------------- writing

    /** Insert a new entry and post it at once (automatic postings). */
    private function postNew(array $header, array $lines, User $by): JournalEntry
    {
        $this->assertBalanced($lines);
        $this->assertOpen($header['entry_date']);

        return $this->write(function () use ($header, $lines, $by) {
            $entry = JournalEntry::create($header + [
                'number' => $this->provisionalNumber(),
                'status' => 'draft',
                'created_by' => $by->id,
            ]);
            $this->insertLines($entry, $lines);
            $this->markPosted($entry, $by);
            AuditTrail::record('posted', $entry, [], $this->snapshot($entry));

            return $entry;
        });
    }

    /** A posted entry with $original's lines swapped; $header overrides the copied source. */
    private function writeReversal(JournalEntry $original, User $by, string $date, ?string $description, array $header = []): JournalEntry
    {
        $original->loadMissing('lines.account', 'reversal');
        if ($original->reverses_entry_id) {
            throw new AccountingException(__('Entry :number is itself a reversal; post a new entry instead.', ['number' => $original->number]));
        }
        if ($original->reversal) {
            throw new AccountingException(__('Entry :number is already reversed by :reversal.', ['number' => $original->number, 'reversal' => $original->reversal->number]));
        }

        $lines = $original->lines->map(fn (JournalLine $l) => [
            'account' => $l->account,
            'party_id' => $l->party_id,
            'debit' => (string) $l->credit,
            'credit' => (string) $l->debit,
            'currency' => $l->currency,
            'amount_currency' => (string) $l->amount_currency,
            'rate' => (string) $l->rate,
            'memo' => $l->memo,
        ])->all();

        return $this->postNew(array_merge([
            'entry_date' => $date,
            'event' => $original->event,
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
        ], $header, [
            'description' => $description ?: $this->stored('Reversal of :number', ['number' => $original->number]),
            'reverses_entry_id' => $original->id,
        ]), $lines, $by);
    }

    private function insertLines(JournalEntry $entry, array $lines): void
    {
        foreach ($lines as $line) {
            $this->assertPostable($line['account'], $line['currency']);
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account']->id,
                'party_id' => $line['party_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'currency' => $line['currency'],
                'amount_currency' => $line['amount_currency'],
                'rate' => $line['rate'],
                'memo' => $line['memo'] !== null ? mb_substr($line['memo'], 0, 255) : null,
            ]);
        }
    }

    private function markPosted(JournalEntry $entry, User $by): void
    {
        $entry->update([
            'status' => 'posted',
            'number' => $this->numbers->next('journal', $entry->entry_date),
            'posted_by' => $by->id,
            'posted_at' => now(),
        ]);
    }

    /** Run $write in a transaction; a refusal from the triggers becomes a message. */
    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            throw AccountingException::fromQuery($e);
        }
    }

    private function stored(string $text, array $replace = []): string
    {
        return __($text, $replace, self::STORED_LOCALE);
    }

    private function provisionalNumber(): string
    {
        return 'DRAFT-'.Str::ulid();
    }

    // ---------------------------------------------------------------- checks (messages; the database is the gate)

    private function authorize(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function assertDraft(JournalEntry $entry): void
    {
        if ($entry->isPosted()) {
            throw new AccountingException(__('Entry :number is posted and never changes; reverse it instead.', ['number' => $entry->number]));
        }
    }

    private function assertPostable(Account $account, ?string $currency = null): void
    {
        if (! $account->is_postable) {
            throw new AccountingException(__('Account :code is a heading; post to one of its sub-accounts.', ['code' => $account->code]));
        }
        if (! $account->active) {
            throw new AccountingException(__('Account :code is inactive.', ['code' => $account->code]));
        }
        if ($currency !== null && $account->currency !== null && $account->currency !== $currency) {
            throw new AccountingException(__('Account :code takes :currency only; this line is in :line.', [
                'code' => $account->code, 'currency' => $account->currency, 'line' => $currency,
            ]));
        }
    }

    /** @param list<array{debit: string, credit: string}> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = Decimal::sum(array_column($lines, 'debit'));
        $credit = Decimal::sum(array_column($lines, 'credit'));
        if (count($lines) < 2 || Decimal::cmp($debit, $credit) !== 0 || Decimal::isZero($debit)) {
            throw new AccountingException(__('The entry does not balance: debits :debit, credits :credit. It needs at least two lines and equal debits and credits.', [
                'debit' => Decimal::format($debit), 'credit' => Decimal::format($credit),
            ]));
        }
    }

    private function assertOpen(string $date): void
    {
        if ($period = $this->ledger->closedPeriodOn($date)) {
            throw new AccountingException(__('The period :period (:from – :to) is closed. Reopen it or choose another date.', [
                'period' => $period->name, 'from' => $period->starts_on->toDateString(), 'to' => $period->ends_on->toDateString(),
            ]));
        }
    }

    /** A payment's own posting: its original entry, or — for a reversal payment — the reversal entry. */
    private function anyPostingOf(Payment $payment): ?JournalEntry
    {
        return JournalEntry::query()->where('source_type', self::SOURCE_PAYMENT)->where('source_id', $payment->id)->first();
    }

    private function unreversedInvoiceOfAnotherRevision(ProformaInvoice $pi): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::SOURCE_PI)->where('event', 'client_invoice')->whereNull('reverses_entry_id')
            ->whereIn('source_id', ProformaInvoice::query()->where('pi_number', $pi->pi_number)->whereKeyNot($pi->id)->select('id'))
            ->whereDoesntHave('reversal')
            ->first();
    }

    /** @return array<string, mixed> */
    private function snapshot(JournalEntry $entry): array
    {
        return $entry->only(['number', 'entry_date', 'status', 'event', 'source_type', 'source_id', 'description', 'reverses_entry_id'])
            + ['lines' => JournalLine::query()->where('journal_entry_id', $entry->id)->orderBy('id')
                ->get(['account_id', 'party_id', 'debit', 'credit', 'currency', 'amount_currency', 'rate', 'memo'])->toArray()];
    }
}
