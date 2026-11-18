<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\MoneyAccount;
use App\Models\OrderStepDefinition;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\PayrollRun;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Claims\NoteQueries;
use App\Services\Numbering\NumberingService;
use App\Services\SupplierOrders\OrderStepService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of payments and payment_approvals (spec §3.5). Payments are append-only
 * (I2): a correction is a reversal row repeating the payment, never an edit. A payment is in
 * its money account's currency and carries the rate to the book currency it was made at (I7).
 * book_amount, signed_amount and signed_book_amount are generated: never written here.
 */
class PaymentService
{
    /** Which party kind each kind of payment is with (Party flag), when it needs one. */
    private const PARTY_KIND = ['client_receipt' => 'is_client', 'supplier_payment' => 'is_supplier', 'shipping_payment' => 'is_shipping'];

    /** Kinds that need a counter account: the account the money comes from or goes to. */
    private const NEEDS_COUNTER_ACCOUNT = ['expense', 'other_in', 'other_out'];

    /** Which kinds may link which document. */
    private const LINKS = [
        'proforma_invoice_id' => ['client_receipt', 'expense'],
        'supplier_order_id' => ['supplier_payment', 'expense'],
        'container_id' => ['shipping_payment', 'expense'],
        'payroll_run_id' => ['salary_payment'],
    ];

    public function __construct(
        private NumberingService $numbers,
        private FxRateService $rates,
        private AttachmentService $attachments,
    ) {}

    /**
     * Record one payment of a kind in PaymentQueries::RECORDABLE_KINDS.
     *
     * @param  array<string, mixed>  $data  kind, paid_on, money_account_id, amount, rate (optional), party_id,
     *                                      proforma_invoice_id, supplier_order_id, container_id, counter_account_id, reference, note
     * @param  list<UploadedFile>  $evidence  bank slips or screenshots
     */
    public function record(array $data, User $by, array $evidence = []): Payment
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, PaymentQueries::RECORDABLE_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('Choose what the payment is.')]);
        }
        $paidOn = $this->date($data['paid_on'] ?? null, 'paid_on');
        $account = $this->activeAccount($data['money_account_id'] ?? null, 'money_account_id');
        $amount = $this->amount($data['amount'] ?? null, 'amount');
        $rate = $this->rate($account->currency, $paidOn, $data['rate'] ?? null, 'rate');
        $links = $this->links($kind, $data);

        return DB::transaction(function () use ($kind, $paidOn, $account, $amount, $rate, $links, $data, $by, $evidence) {
            $this->holdAccounts($account);
            $payment = Payment::create([
                'number' => $this->numbers->next('payment', $paidOn),
                'paid_on' => $paidOn->toDateString(),
                'kind' => $kind,
                'direction' => in_array($kind, Payment::KINDS_IN, true) ? 'in' : 'out',
                'money_account_id' => $account->id,
                'currency' => $account->currency,
                'amount' => $amount,
                'rate' => $rate,
                'reference' => $this->text($data['reference'] ?? null, 120),
                'note' => $this->text($data['note'] ?? null),
                'user_id' => $by->id,
            ] + $links);
            AuditTrail::record('created', $payment, [], $this->audited($payment));
            $this->storeEvidence($payment, $evidence, $by);
            $this->syncStepsForPayment($payment, $by);

            return $payment;
        });
    }

    /**
     * Move money between two of MASDAR's own accounts: a transfer_out row from one and a
     * transfer_in row to the other, under one transfer_group. Between currencies the amounts
     * differ (an exchange), each with its own rate; in one currency they are equal (a fee is
     * an expense of its own).
     *
     * @param  array<string, mixed>  $data  from_account_id, to_account_id, paid_on, amount, rate, in_paid_on, in_amount, in_rate, reference, note
     * @param  list<UploadedFile>  $evidence  attached to the outgoing row
     * @return array{out: Payment, in: Payment}
     */
    public function transfer(array $data, User $by, array $evidence = []): array
    {
        $from = $this->activeAccount($data['from_account_id'] ?? null, 'from_account_id');
        $to = $this->activeAccount($data['to_account_id'] ?? null, 'to_account_id');
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => __('Choose two different accounts.')]);
        }
        $paidOn = $this->date($data['paid_on'] ?? null, 'paid_on');
        $arrivedOn = filled($data['in_paid_on'] ?? null) ? $this->date($data['in_paid_on'], 'in_paid_on') : $paidOn;
        if ($arrivedOn->lt($paidOn)) {
            throw ValidationException::withMessages(['in_paid_on' => __('The money cannot arrive before it leaves.')]);
        }
        $outAmount = $this->amount($data['amount'] ?? null, 'amount');
        if ($from->currency === $to->currency) {
            $inAmount = filled($data['in_amount'] ?? null) ? $this->amount($data['in_amount'], 'in_amount') : $outAmount;
            if (bccomp($inAmount, $outAmount, 4) !== 0) {
                throw ValidationException::withMessages(['in_amount' => __('Both accounts are in :currency, so the same amount arrives as leaves. Record a transfer fee as an expense.', ['currency' => $from->currency])]);
            }
        } else {
            $inAmount = $this->amount($data['in_amount'] ?? null, 'in_amount');
        }
        $outRate = $this->rate($from->currency, $paidOn, $data['rate'] ?? null, 'rate');
        $inRate = $this->rate($to->currency, $arrivedOn, $data['in_rate'] ?? null, 'in_rate');

        return DB::transaction(function () use ($from, $to, $paidOn, $arrivedOn, $outAmount, $inAmount, $outRate, $inRate, $data, $by, $evidence) {
            $this->holdAccounts($from, $to);
            $common = [
                'transfer_group' => (string) Str::uuid(),
                'reference' => $this->text($data['reference'] ?? null, 120),
                'note' => $this->text($data['note'] ?? null),
                'user_id' => $by->id,
            ];
            $out = Payment::create($common + [
                'number' => $this->numbers->next('payment', $paidOn),
                'paid_on' => $paidOn->toDateString(), 'kind' => 'transfer_out', 'direction' => 'out',
                'money_account_id' => $from->id, 'currency' => $from->currency, 'amount' => $outAmount, 'rate' => $outRate,
            ]);
            $in = Payment::create($common + [
                'number' => $this->numbers->next('payment', $arrivedOn),
                'paid_on' => $arrivedOn->toDateString(), 'kind' => 'transfer_in', 'direction' => 'in',
                'money_account_id' => $to->id, 'currency' => $to->currency, 'amount' => $inAmount, 'rate' => $inRate,
            ]);
            AuditTrail::record('created', $out, [], $this->audited($out));
            AuditTrail::record('created', $in, [], $this->audited($in));
            $this->storeEvidence($out, $evidence, $by);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * A manager's approval of an outgoing payment (spec §6): one row, once. Transfers are
     * approved on their outgoing row. A payment already reversed is not approved; the reversal
     * of money out is approved like any payment out, once the payment it reverses is approved
     * (until then neither counts, so they cannot count apart).
     */
    public function approve(Payment $payment, User $by, ?string $note = null): PaymentApproval
    {
        if (! $by->can('payments.approve')) {
            throw new AuthorizationException(__('Only a manager approves payments.'));
        }

        return DB::transaction(function () use ($payment, $by, $note) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $refuse = match (true) {
                $payment->direction !== 'out' => __('Only outgoing payments are approved.'),
                $payment->reversal()->exists() => __('This payment has been reversed.'),
                $payment->reverses_payment_id !== null && ! PaymentApproval::query()->where('payment_id', $payment->reverses_payment_id)->exists() => __('The payment this reverses was never approved, so neither of them counts and there is nothing to approve.'),
                $payment->approval()->exists() => __('This payment is already approved.'),
                default => null,
            };
            if ($refuse) {
                throw ValidationException::withMessages(['payment' => $refuse]);
            }

            $approval = PaymentApproval::create([
                'payment_id' => $payment->id,
                'approved_by' => $by->id,
                'note' => $this->text($note),
            ]);
            AuditTrail::record('approved', $payment, [], ['number' => $payment->number, 'approval_id' => $approval->id, 'note' => $approval->note]);
            $this->syncStepsForPayment($payment->fresh(), $by);

            return $approval;
        });
    }

    /**
     * Correct a payment by a reversal row that repeats it (same kind, direction, account,
     * amount, rate and links) with reverses_payment_id, today, the reason in its note. A
     * transfer is reversed as a whole: both rows. A reversal is never reversed.
     *
     * @return list<Payment> the reversal rows
     */
    public function reverse(Payment $payment, User $by, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Say why the payment is reversed.')]);
        }

        return DB::transaction(function () use ($payment, $by, $reason) {
            if ($payment->reverses_payment_id !== null) {
                throw ValidationException::withMessages(['reason' => __('A reversal cannot be reversed. Record the payment again instead.')]);
            }
            // Lock every row reversed together, in id order, so two people never interleave.
            $legs = Payment::query()
                ->when($payment->transfer_group,
                    fn ($q, $group) => $q->where('transfer_group', $group)->whereNull('reverses_payment_id'),
                    fn ($q) => $q->whereKey($payment->id))
                ->orderBy('id')->lockForUpdate()->get();
            if (Payment::query()->whereIn('reverses_payment_id', $legs->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['reason' => __('This payment has already been reversed.')]);
            }

            $today = CarbonImmutable::now();
            $reversals = [];
            foreach ($legs as $leg) {
                $reversal = Payment::create([
                    'number' => $this->numbers->next('payment', $today),
                    'paid_on' => $today->toDateString(),
                    'reverses_payment_id' => $leg->id,
                    'note' => $reason,
                    'user_id' => $by->id,
                ] + $leg->only([
                    'kind', 'direction', 'money_account_id', 'currency', 'amount', 'rate', 'party_id', 'proforma_invoice_id',
                    'supplier_order_id', 'container_id', 'payroll_run_id', 'counter_account_id', 'transfer_group', 'reference',
                ]));
                AuditTrail::record('created', $reversal, [], $this->audited($reversal));
                AuditTrail::record('reversed', $leg, [], ['number' => $leg->number, 'reversal' => $reversal->number, 'reason' => $reason]);
                $this->syncStepsForPayment($reversal, $by);
                $reversals[] = $reversal;
            }

            return $reversals;
        });
    }

    /**
     * A supplier payment moves its order's payment steps (spec S2: "Deposit", "Money transfer
     * to factory" — every active step of kind `payment`, in board order). Once some of the
     * order is paid, the first is done; once all of it is paid, every one is. A reversal that
     * takes the paid figure back down reopens what no longer holds. Only payments that count
     * (PaymentQueries::isEffective) move a step: one awaiting approval moves it when approved.
     * A manager turns this off by giving those steps another kind on Lists & options.
     */
    private function syncStepsForPayment(Payment $payment, User $by): void
    {
        if ($payment->kind !== 'supplier_payment' || $payment->supplier_order_id === null) {
            return;
        }
        $order = SupplierOrder::query()->find($payment->supplier_order_id);
        if (! $order) {
            return;
        }
        $queries = app(PaymentQueries::class);
        // Link the step to this payment only when it is the money that just started to count.
        $paymentId = $payment->reverses_payment_id === null && $queries->isEffective($payment) ? $payment->id : null;

        $this->syncOrderPaymentSteps($order, $by, $payment->reverses_payment_id !== null, 'Payment '.$payment->number, $paymentId, $payment->paid_on?->toDateString());
    }

    /**
     * Bring $order's payment steps in line with what is paid against what is due: the order's
     * total less its issued debit notes (spec §5.8). $reopen lets a step that no longer holds
     * go back (a reversal, a voided debit note); otherwise a step only moves forward.
     */
    public function syncOrderPaymentSteps(SupplierOrder $order, User $by, bool $reopen = false, string $note = '', ?int $paymentId = null, ?string $on = null): void
    {
        $codes = OrderStepDefinition::query()->where('active', true)->where('kind', 'payment')->ordered()->pluck('code')->all();
        if ($codes === []) {
            return;
        }

        $paid = app(PaymentQueries::class)->paidToSupplierOrder($order);
        $total = (string) DB::table('supplier_order_lines')->where('supplier_order_id', $order->id)->sum('amount');
        $due = bcsub($total, app(NoteQueries::class)->debitedForOrder($order->id, $order->currency), 4);
        $steps = app(OrderStepService::class);

        $paidSome = bccomp($paid, '0', 4) > 0;
        $paidAll = $paidSome && bccomp($paid, $due, 4) >= 0;
        foreach ($codes as $i => $code) {
            $isFirst = $i === 0;
            $shouldBeDone = $paidAll || ($isFirst && $paidSome);
            if ($shouldBeDone) {
                $steps->recordAutomatic($order, $code, $by, $note, $paymentId, $on);
            } elseif ($reopen) {
                // Only a reversal or a voided debit note takes a step back; a step a person marked done stays done.
                $steps->reopenAutomatic($order, $code, $by, $note);
            }
        }
    }

    /** @return array<string, int|null> the document links $kind may carry, checked */
    private function links(string $kind, array $data): array
    {
        $id = fn (string $key) => filled($data[$key] ?? null) ? (int) $data[$key] : null;
        $links = [
            'party_id' => $id('party_id'),
            'proforma_invoice_id' => $id('proforma_invoice_id'),
            'supplier_order_id' => $id('supplier_order_id'),
            'container_id' => $id('container_id'),
            'payroll_run_id' => $id('payroll_run_id'),
            'counter_account_id' => in_array($kind, self::NEEDS_COUNTER_ACCOUNT, true) ? $id('counter_account_id') : null,
        ];
        $errors = [];

        foreach (self::LINKS as $key => $kinds) {
            if ($links[$key] !== null && ! in_array($kind, $kinds, true)) {
                $errors[$key] = __('This kind of payment is not linked to that document.');
            }
        }

        $party = $links['party_id'] !== null ? Party::query()->find($links['party_id']) : null;
        if ($links['party_id'] !== null && ! $party) {
            $errors['party_id'] = __('That party does not exist.');
        }
        if ($flag = self::PARTY_KIND[$kind] ?? null) {
            $message = match ($kind) {
                'client_receipt' => __('Choose the client who paid.'),
                'supplier_payment' => __('Choose the supplier paid.'),
                default => __('Choose the shipping company paid.'),
            };
            if (! $party || ! $party->{$flag}) {
                $errors['party_id'] = $message;
            }
        }

        if ($links['proforma_invoice_id'] !== null && ! isset($errors['proforma_invoice_id'])) {
            $pi = ProformaInvoice::query()->find($links['proforma_invoice_id']);
            // A receipt is from the PI's client; an expense may be a cost of any PI (spec §5.8, profit).
            if (! $pi || ($kind === 'client_receipt' && (! $party || $pi->client_id !== $party->id))) {
                $errors['proforma_invoice_id'] = __('Choose a PI of this client.');
            }
        }
        if ($links['supplier_order_id'] !== null && ! isset($errors['supplier_order_id'])) {
            $order = SupplierOrder::query()->find($links['supplier_order_id']);
            if (! $order || ($kind === 'supplier_payment' && (! $party || $order->supplier_id !== $party->id))) {
                $errors['supplier_order_id'] = __('Choose an order of this supplier.');
            }
        }
        if ($links['container_id'] !== null && ! isset($errors['container_id']) && ! Container::query()->whereKey($links['container_id'])->exists()) {
            $errors['container_id'] = __('That container does not exist.');
        }

        // Salaries are paid against an approved payroll run, from an account in its currency (spec §5.7).
        if ($kind === 'salary_payment' && ! isset($errors['payroll_run_id'])) {
            $run = $links['payroll_run_id'] !== null ? PayrollRun::query()->find($links['payroll_run_id']) : null;
            $account = MoneyAccount::query()->find($data['money_account_id'] ?? null);
            if (! $run || $run->status !== 'approved') {
                $errors['payroll_run_id'] = __('Choose the approved payroll run the salaries are paid for.');
            } elseif ($account && $account->currency !== $run->currency) {
                $errors['money_account_id'] = __('Payroll run :number is in :currency: pay it from an account in :currency.', ['number' => $run->number, 'currency' => $run->currency]);
            }
        }

        if (in_array($kind, self::NEEDS_COUNTER_ACCOUNT, true)) {
            $counter = $links['counter_account_id'] !== null ? Account::query()->find($links['counter_account_id']) : null;
            if (! $counter || ! $counter->is_postable || ! $counter->active) {
                $errors['counter_account_id'] = __('Choose the account the money is for: an active account that can be posted to (not a heading).');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $links;
    }

    /**
     * Hold the accounts' rows (FOR SHARE, in id order) until the payment commits, so an account's
     * currency cannot change between the check above and the insert (money_accounts_currency_guard).
     */
    private function holdAccounts(MoneyAccount ...$accounts): void
    {
        $ids = collect($accounts)->pluck('id')->sort()->values();
        $held = MoneyAccount::query()->whereKey($ids)->orderBy('id')->sharedLock()->get(['id', 'currency', 'active'])->keyBy('id');
        foreach ($accounts as $account) {
            $now = $held->get($account->id);
            if (! $now || ! $now->active || $now->currency !== $account->currency) {
                throw ValidationException::withMessages(['money_account_id' => __('The money account changed while the payment was being saved. Check it and try again.')]);
            }
        }
    }

    private function activeAccount(mixed $id, string $field): MoneyAccount
    {
        $account = filled($id) ? MoneyAccount::query()->find((int) $id) : null;
        if (! $account || ! $account->active) {
            throw ValidationException::withMessages([$field => __('Choose an active money account.')]);
        }

        return $account;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Give a valid date.')]);
        }
    }

    /** A positive amount with up to 4 decimals, as a string (never a float). */
    private function amount(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => __('The amount is a number greater than zero, with up to 4 decimals.')]);
        }

        return $value;
    }

    /**
     * The rate to the book currency: 1 for the book currency; otherwise the rate typed (the
     * one actually obtained), or the FX table's rate for the day. Refused when there is neither.
     */
    private function rate(string $currency, CarbonImmutable $on, mixed $typed, string $field): string
    {
        $book = BusinessSetting::current()->book_currency;
        if ($currency === $book) {
            return '1';
        }
        $typed = trim((string) $typed);
        if ($typed !== '') {
            if (! preg_match('/^\d{1,12}(\.\d{1,6})?$/', $typed) || bccomp($typed, '0', 6) <= 0) {
                throw ValidationException::withMessages([$field => __('The rate is a number greater than zero, with up to 6 decimals.')]);
            }

            return $typed;
        }

        return $this->rates->rateFor($currency, $on)
            ?? throw ValidationException::withMessages([$field => __('There is no :currency rate on or before :date. Type the rate obtained (:book for one :currency), or add it to the FX rates.', [
                'currency' => $currency, 'date' => $on->toDateString(), 'book' => $book,
            ])]);
    }

    private function text(mixed $value, ?int $max = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    /** @param list<UploadedFile> $evidence */
    private function storeEvidence(Payment $payment, array $evidence, User $by): void
    {
        foreach ($evidence as $file) {
            if ($file instanceof UploadedFile) {
                $this->attachments->store($payment, $file, $by->id);
            }
        }
    }

    private function audited(Payment $payment): array
    {
        return $payment->only([
            'number', 'paid_on', 'kind', 'direction', 'money_account_id', 'currency', 'amount', 'rate', 'party_id', 'proforma_invoice_id',
            'supplier_order_id', 'container_id', 'payroll_run_id', 'counter_account_id', 'transfer_group', 'reverses_payment_id', 'reference', 'note',
        ]);
    }
}
