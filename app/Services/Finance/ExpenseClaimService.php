<?php

namespace App\Services\Finance;

use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimDecision;
use App\Models\ExpenseClaimLine;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Accounting\OpenPeriods;
use App\Services\Hr\Money;
use App\Services\Notifications\NotificationService;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use App\Support\Database\Refusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of expense_claims, expense_claim_lines and expense_claim_decisions (spec
 * §5.8). Anyone signed in claims their own expenses: a draft with its receipts, then submitted;
 * whoever has expense_claims.approve approves, rejects or returns it (never their own —
 * PostgreSQL refuses that and decisions out of order). An approved claim is posted from the
 * accounting queue and paid back with a payment of kind claim_payment.
 */
class ExpenseClaimService
{
    public function __construct(private NumberingService $numbers) {}

    public function create(array $data, User $by, ?User $for = null): ExpenseClaim
    {
        $for ??= $by;
        if ($for->id !== $by->id && ! $by->can('expense_claims.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            throw ValidationException::withMessages(['currency' => __('Choose a currency from the list of currencies.')]);
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('Give the claim a title, e.g. the trip or the month.')]);
        }

        return DB::transaction(function () use ($for, $by, $currency, $title) {
            $claim = ExpenseClaim::create(['number' => $this->numbers->next('expense_claim'), 'user_id' => $for->id, 'currency' => $currency,
                'title' => mb_substr($title, 0, 200), 'created_by' => $by->id]);
            AuditTrail::record('created', $claim, [], ['for' => $for->name, 'currency' => $currency, 'title' => $title]);

            return $claim;
        });
    }

    public function addLine(ExpenseClaim $claim, array $data, User $by): ExpenseClaimLine
    {
        $this->assertOwnDraft($claim, $by);
        $values = $this->lineValues($data);

        return $this->write(function () use ($claim, $values) {
            $line = ExpenseClaimLine::create($values + ['expense_claim_id' => $claim->id]);
            AuditTrail::record('line_added', $claim, [], $values);

            return $line;
        });
    }

    public function deleteLine(ExpenseClaimLine $line, User $by): void
    {
        $this->assertOwnDraft($line->claim, $by);
        $this->write(function () use ($line) {
            $old = $line->only(['spent_on', 'category', 'description', 'amount']);
            $line->delete();
            AuditTrail::record('line_removed', $line->claim, $old, []);
        });
    }

    public function submit(ExpenseClaim $claim, User $by): ExpenseClaimDecision
    {
        $this->assertOwnDraft($claim, $by);
        if (bccomp($claim->total(), '0', 4) <= 0) {
            throw ValidationException::withMessages(['claim' => __('Add the receipts before submitting the claim.')]);
        }
        $decision = $this->decide($claim, 'submitted', $by, null);
        foreach (User::permission('expense_claims.approve')->where('status', 'active')->where('id', '<>', $claim->user_id)->get() as $approver) {
            $this->inLocale($approver, fn () => app(NotificationService::class)->send($approver, __('Expense claim to decide'),
                __(':name claims :amount :currency (:number).', ['name' => $claim->user?->name, 'amount' => $claim->total(), 'currency' => $claim->currency, 'number' => $claim->number]),
                ['icon' => 'receipt-cutoff', 'category' => 'expense_claims', 'url' => route('expense-claims.show', $claim)]));
        }

        return $decision;
    }

    /** approved | rejected | returned */
    public function decideAs(ExpenseClaim $claim, string $decision, User $by, ?string $note): ExpenseClaimDecision
    {
        if (! $by->can('expense_claims.approve')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
        if (! in_array($decision, ['approved', 'rejected', 'returned'], true)) {
            throw new \InvalidArgumentException("Unknown decision [{$decision}].");
        }
        if ((int) $claim->user_id === (int) $by->id) {
            throw ValidationException::withMessages(['claim' => __('Someone else decides your own claim.')]);
        }
        if ($decision !== 'approved' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => __('Say why, so the claimant knows what to change.')]);
        }

        $row = $this->decide($claim, $decision, $by, $note);
        $claimant = $claim->user;
        if ($claimant) {
            $this->inLocale($claimant, fn () => app(NotificationService::class)->send($claimant,
                __('Your expense claim :number: :status', ['number' => $claim->number, 'status' => mb_strtolower(ExpenseClaim::statusLabel($decision))]),
                $row->note ?? __('Paid back by the accountant from here.'),
                ['icon' => 'receipt-cutoff', 'category' => 'expense_claims', 'type' => $decision === 'approved' ? 'success' : 'warning', 'url' => route('my-expenses.show', $claim)]));
        }

        return $row;
    }

    private function inLocale(User $user, callable $send): void
    {
        $previous = app()->getLocale();
        app()->setLocale($user->locale ?: config('app.locale'));
        try {
            $send();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function decide(ExpenseClaim $claim, string $decision, User $by, ?string $note): ExpenseClaimDecision
    {
        return $this->write(function () use ($claim, $decision, $by, $note) {
            ExpenseClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $row = ExpenseClaimDecision::create(['expense_claim_id' => $claim->id, 'decision' => $decision,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 2000) : null, 'user_id' => $by->id]);
            AuditTrail::record($decision, $claim, [], ['note' => $row->note, 'total' => $claim->total()]);

            return $row;
        });
    }

    private function lineValues(array $data): array
    {
        $errors = [];
        $category = (string) ($data['category'] ?? '');
        if (! array_key_exists($category, Lookup::options('expense_category'))) {
            $errors['category'] = __('Choose what the expense was.');
        }
        $date = (string) ($data['spent_on'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['spent_on'] = __('Enter a valid date.');
        } elseif ($date > now()->toDateString()) {
            // B28: a receipt is for money already spent.
            $errors['spent_on'] = __('A receipt cannot be dated after today.');
        } elseif ($closed = OpenPeriods::closedOn($date)) {
            $errors['spent_on'] = __('The period :period (:from – :to) is closed. Choose a date in an open period, or ask the accountant to reopen it.', [
                'period' => $closed->name, 'from' => $closed->starts_on->toDateString(), 'to' => $closed->ends_on->toDateString(),
            ]);
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $errors['description'] = __('Say what it was for.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'spent_on' => $date, 'category' => $category, 'description' => mb_substr($description, 0, 2000),
            'amount' => Money::amount($data['amount'] ?? null, 'amount', true, decimals: 2),
            'proforma_invoice_id' => filled($data['proforma_invoice_id'] ?? null) ? (int) $data['proforma_invoice_id'] : null,
            'container_id' => filled($data['container_id'] ?? null) ? (int) $data['container_id'] : null,
        ];
    }

    private function assertOwnDraft(ExpenseClaim $claim, User $by): void
    {
        if ((int) $claim->user_id !== (int) $by->id && ! $by->can('expense_claims.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
        if (! $claim->fresh('latestDecision')->isEditable()) {
            throw ValidationException::withMessages(['claim' => __('A submitted claim does not change. It is returned to you if something must.')]);
        }
    }

    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23001', 'P0001', '23503'], true)) {
                throw ValidationException::withMessages(['claim' => Refusal::message($e)]);
            }
            throw $e;
        }
    }
}
