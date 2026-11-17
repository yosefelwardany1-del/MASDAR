<?php

namespace App\Services\Finance;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\Payment;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only questions about payments, for this module and the others (PIs, supplier orders,
 * the ledger, the dashboard). Nothing here writes.
 *
 * Effective (plan, "Contracts between modules", taken as written): a payment counts — is
 * posted, is paid — when it is incoming, when `payments_out_require_approval` is off, or when
 * it has its own payment_approvals row. A reversal repeats its payment's direction, so the
 * reversal of money out is itself approved — and only once the payment it reverses is
 * approved (trigger payment_approvals_guard). A payment reversed before its approval is never
 * approved, and neither is its reversal: neither ever counts. So an original and its
 * reversal never count apart in the wrong order, and the ledger (module 6, the same rule)
 * and these balances agree. The setting is read when the question is asked.
 */
class PaymentQueries
{
    /** The kinds recorded on the payment form; transfers have their own form. */
    public const RECORDABLE_KINDS = ['client_receipt', 'supplier_payment', 'shipping_payment', 'expense', 'other_in', 'other_out'];

    public function approvalRequired(): bool
    {
        return (bool) BusinessSetting::current()->payments_out_require_approval;
    }

    public function isEffective(Payment $payment, ?bool $approvalRequired = null): bool
    {
        return $payment->direction === 'in'
            || ! ($approvalRequired ?? $this->approvalRequired())
            || $payment->approval !== null;
    }

    /** Narrow a query on `payments` (or an alias of it) to the effective rows. */
    public function whereEffective(EloquentBuilder|QueryBuilder $query, string $table = 'payments'): EloquentBuilder|QueryBuilder
    {
        if (! $this->approvalRequired()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where("{$table}.direction", 'in')
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('payment_approvals')
                ->whereColumn('payment_approvals.payment_id', "{$table}.id")));
    }

    /** The same rule as a boolean SQL expression over the payments row $alias, for raw SQL. */
    public function effectiveSql(string $alias = 'payments'): string
    {
        if (! $this->approvalRequired()) {
            return 'TRUE';
        }

        return "({$alias}.direction = 'in' OR EXISTS (SELECT 1 FROM payment_approvals pa_eff WHERE pa_eff.payment_id = {$alias}.id))";
    }

    /**
     * Outgoing payments a manager can approve now: not approved, not reversed, and — for a
     * reversal — the payment it reverses already approved. Nothing while approval is off.
     */
    public function whereAwaitingApproval(EloquentBuilder|QueryBuilder $query, string $table = 'payments'): EloquentBuilder|QueryBuilder
    {
        if (! $this->approvalRequired()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where("{$table}.direction", 'out')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_approvals')->whereColumn('payment_approvals.payment_id', "{$table}.id"))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments as rev_aw')->whereColumn('rev_aw.reverses_payment_id', "{$table}.id"))
            ->where(fn ($q) => $q->whereNull("{$table}.reverses_payment_id")
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('payment_approvals as orig_aw')
                    ->whereColumn('orig_aw.payment_id', "{$table}.reverses_payment_id")));
    }

    /** Whether the approval rules allow approving $payment now (the permission is checked apart). */
    public function canBeApproved(Payment $payment): bool
    {
        return $payment->direction === 'out'
            && $payment->approval === null
            && $payment->reversal === null
            && ($payment->reverses_payment_id === null || $payment->reverses?->approval !== null);
    }

    /** True when $payment is waiting for a manager: approval is on and it can be approved. */
    public function awaitsApproval(Payment $payment, ?bool $approvalRequired = null): bool
    {
        return ($approvalRequired ?? $this->approvalRequired()) && $this->canBeApproved($payment);
    }

    /** One of: reversal, reversed, approved, effective, awaiting (a reversal may also await: awaitsApproval). */
    public function status(Payment $payment, ?bool $approvalRequired = null): string
    {
        return match (true) {
            $payment->reverses_payment_id !== null => 'reversal',
            $payment->reversal !== null => 'reversed',
            $payment->approval !== null => 'approved',
            $this->isEffective($payment, $approvalRequired) => 'effective',
            default => 'awaiting',
        };
    }

    /**
     * Paid on a PI number (plan, "PI paid amounts"): SUM(signed_amount) of the effective client
     * receipts in $currency linked to any revision of $piNumber. Reversals net themselves out.
     */
    public function paidForPiNumber(string $piNumber, string $currency): string
    {
        $query = DB::table('payments')
            ->join('proforma_invoices', 'proforma_invoices.id', '=', 'payments.proforma_invoice_id')
            ->where('proforma_invoices.pi_number', $piNumber)
            ->where('payments.kind', 'client_receipt')
            ->where('payments.currency', strtoupper($currency));
        $this->whereEffective($query);

        return (string) $query->selectRaw('COALESCE(SUM(payments.signed_amount), 0)::numeric(18,4) AS paid')->first()->paid;
    }

    /**
     * Paid to a supplier order: the effective supplier payments linked to it, in the order's
     * currency (or $currency), as a positive amount. Payments in another currency are not
     * converted: ask for that currency.
     */
    public function paidToSupplierOrder(SupplierOrder $order, ?string $currency = null): string
    {
        $query = DB::table('payments')
            ->where('payments.supplier_order_id', $order->id)
            ->where('payments.kind', 'supplier_payment')
            ->where('payments.currency', strtoupper($currency ?? $order->currency));
        $this->whereEffective($query);

        return (string) $query->selectRaw('(-COALESCE(SUM(payments.signed_amount), 0))::numeric(18,4) AS paid')->first()->paid;
    }

    /**
     * What each money account holds, in its own currency: SUM(signed_amount) of its effective
     * payments. Every account is listed, with '0.0000' when nothing has moved.
     *
     * @return array<int, string> money_account_id => amount
     */
    public function accountBalances(): array
    {
        $rows = DB::select(
            'SELECT ma.id, (SELECT COALESCE(SUM(payments.signed_amount), 0) FROM payments
                             WHERE payments.money_account_id = ma.id AND '.$this->effectiveSql('payments').')::numeric(18,4) AS amount
             FROM money_accounts ma ORDER BY ma.id'
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->id] = (string) $row->amount;
        }

        return $balances;
    }

    /**
     * The cash position: what all money accounts hold together, per currency.
     *
     * @return array<string, string> currency => amount
     */
    public function cashPosition(): array
    {
        $rows = DB::select(
            'SELECT ma.currency, COALESCE(SUM(payments.signed_amount), 0)::numeric(18,4) AS amount
             FROM money_accounts ma
             LEFT JOIN payments ON payments.money_account_id = ma.id AND '.$this->effectiveSql('payments').'
             GROUP BY ma.currency ORDER BY ma.currency'
        );

        $position = [];
        foreach ($rows as $row) {
            $position[$row->currency] = (string) $row->amount;
        }

        return $position;
    }

    /**
     * Narrow a payments query to what $user may see (spec §6): a sales user limited to their
     * own clients sees only those clients' receipts; whoever may not see supplier identities
     * and prices sees no supplier payment.
     */
    public function whereVisibleTo(EloquentBuilder|QueryBuilder $query, User $user, string $table = 'payments'): EloquentBuilder|QueryBuilder
    {
        if (Visibility::ownClientsOnly($user)) {
            return $query->where("{$table}.kind", 'client_receipt')
                ->whereIn("{$table}.party_id", Visibility::scopeClients(Party::query()->clients(), $user)->select('id'));
        }
        if (! Visibility::canSeeSupplierPrices($user)) {
            $query->where("{$table}.kind", '<>', 'supplier_payment');
        }

        return $query;
    }

    public function canSee(Payment $payment, User $user): bool
    {
        if (Visibility::ownClientsOnly($user)) {
            return $payment->kind === 'client_receipt' && $payment->party?->account_manager_id === $user->id;
        }

        return $payment->kind !== 'supplier_payment' || Visibility::canSeeSupplierPrices($user);
    }

    /** True when no visibility rule hides a payment from $user (running balances need every row). */
    public function seesEveryPayment(User $user): bool
    {
        return ! Visibility::ownClientsOnly($user) && Visibility::canSeeSupplierPrices($user);
    }

    /** The kinds $user may record: the same rules as what they may see. @return list<string> */
    public function recordableKinds(User $user): array
    {
        if (Visibility::ownClientsOnly($user)) {
            return ['client_receipt'];
        }

        return array_values(array_filter(self::RECORDABLE_KINDS, fn ($kind) => $kind !== 'supplier_payment' || Visibility::canSeeSupplierPrices($user)));
    }

    public function canTransfer(User $user): bool
    {
        return ! Visibility::ownClientsOnly($user);
    }
}
