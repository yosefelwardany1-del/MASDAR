<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only questions the ledger asks of itself and of the other modules' tables: the book
 * currency, the FX rate on a date, which payments are effective (the phase-1 contract), the
 * posting a source already has, the closed period a date falls in, and which parties a user
 * may see named. Writes nothing.
 */
class Ledger
{
    public function bookCurrency(): string
    {
        return (string) BusinessSetting::current()->book_currency;
    }

    /**
     * Book-currency units for one unit of $currency: 1 for the book currency, else the latest
     * fx_rates row on or before $date. Fails loudly when there is none (spec I7: never guessed).
     */
    public function rateOn(string $currency, string $date): string
    {
        if ($currency === $this->bookCurrency()) {
            return '1.000000';
        }

        $rate = DB::table('fx_rates')->where('currency', $currency)->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')->value('rate');

        if ($rate === null) {
            throw new AccountingException(__('There is no :currency rate on or before :date. Enter one on the FX rates screen (Payments) first.', [
                'currency' => $currency, 'date' => $date,
            ]));
        }

        return (string) $rate;
    }

    /** @return array<string, string> currency => latest rate (for the entry form's defaults). */
    public function latestRates(): array
    {
        return DB::table('fx_rates as r')
            ->whereRaw('r.rate_date = (SELECT MAX(rate_date) FROM fx_rates x WHERE x.currency = r.currency)')
            ->pluck('rate', 'currency')->map(fn ($rate) => (string) $rate)->all();
    }

    /**
     * Effective payments (phase-1 contract): incoming, or every payment while
     * payments_out_require_approval is off, or one with a payment_approvals row. Only these
     * are posted or counted as paid.
     */
    public function effectivePayments(): Builder
    {
        $query = Payment::query();
        if (BusinessSetting::current()->payments_out_require_approval) {
            $query->where(fn (Builder $q) => $q->where('payments.direction', 'in')
                ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('payment_approvals')
                    ->whereColumn('payment_approvals.payment_id', 'payments.id')));
        }

        return $query;
    }

    public function isEffective(Payment $payment): bool
    {
        return $this->effectivePayments()->whereKey($payment->getKey())->exists();
    }

    /** The original (not reversal) posting of a source, if any. */
    public function postingOf(string $sourceType, int $sourceId, ?string $event = null): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->when($event, fn ($q) => $q->where('event', $event))
            ->whereNull('reverses_entry_id')
            ->orderBy('id')->first();
    }

    public function closedPeriodOn(string $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()->whereNotNull('closed_at')
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
    }

    /** Accounts that take postings, for the forms: code — name. */
    public function postableAccounts(): Collection
    {
        return Account::query()->where('is_postable', true)->where('active', true)->orderBy('code')->get();
    }

    /**
     * Whether $user may see $party named in a ledger screen: a sales user limited to their own
     * clients sees only those (Visibility::ownClientsOnly); suppliers are named only to those
     * who may see supplier identities (Visibility::canSeeSupplierPrices).
     */
    public function canSeeParty(User $user, ?Party $party): bool
    {
        if ($party === null) {
            return true;
        }
        if ($party->is_client && Visibility::ownClientsOnly($user) && (int) $party->account_manager_id !== (int) $user->id) {
            return false;
        }
        if ($party->is_supplier && ! $party->is_client && ! Visibility::canSeeSupplierPrices($user)) {
            return false;
        }

        return true;
    }

    /** Parties $user may pick for a statement, by kind (client, supplier, shipping). */
    public function statementParties(User $user, string $kind): Builder
    {
        $query = Party::query()->orderBy('name');

        return match ($kind) {
            'client' => Visibility::scopeClients($query->clients(), $user),
            'supplier' => Visibility::canSeeSupplierPrices($user) ? $query->suppliers() : $query->whereRaw('false'),
            default => $query->shippingCompanies(),
        };
    }
}
