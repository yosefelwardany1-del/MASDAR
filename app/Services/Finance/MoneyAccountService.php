<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Lookup;
use App\Models\MoneyAccount;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of money_accounts (spec §3.5): each bank account, e-wallet, cash box or
 * payment agent, in one currency, in Hong Kong or the mainland. Its currency never changes
 * once it has payments (trigger money_accounts_currency_guard; checked here for the message).
 */
class MoneyAccountService
{
    public const FIELDS = ['name', 'kind', 'currency', 'location', 'ledger_account_id', 'details', 'active'];

    /** @param array<string, mixed> $data validated fields (FIELDS) */
    public function create(array $data): MoneyAccount
    {
        return DB::transaction(function () use ($data) {
            $data = $this->checked($data, null);
            $account = MoneyAccount::create($data);
            AuditTrail::record('created', $account, [], $account->only(self::FIELDS));

            return $account;
        });
    }

    /** @param array<string, mixed> $data validated fields (FIELDS) */
    public function update(MoneyAccount $account, array $data): MoneyAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $account = MoneyAccount::query()->lockForUpdate()->findOrFail($account->id);
            $data = $this->checked($data, $account);
            if ($data['currency'] !== $account->currency && $account->payments()->exists()) {
                throw ValidationException::withMessages([
                    'currency' => __('This account has payments; its currency cannot change. Open a new account for the other currency.'),
                ]);
            }
            $old = $account->only(array_keys($data));
            $account->update($data);
            AuditTrail::record('updated', $account, $old, $account->only(array_keys($data)));

            return $account;
        });
    }

    /** The rules every write keeps, whoever calls: the screen's validation only shapes the input. */
    private function checked(array $data, ?MoneyAccount $account): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $data['currency'] = strtoupper((string) ($data['currency'] ?? ''));
        $data['ledger_account_id'] = ($data['ledger_account_id'] ?? null) ?: null;
        $data['details'] = ($data['details'] ?? null) ?: null;
        $data['active'] = (bool) ($data['active'] ?? true);

        $errors = [];
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = __('Give the account a name.');
        }
        if (! in_array($data['kind'] ?? null, MoneyAccount::KINDS, true)) {
            $errors['kind'] = __('Choose the kind of account.');
        }
        if (! in_array($data['location'] ?? null, MoneyAccount::LOCATIONS, true)) {
            $errors['location'] = __('Choose where the account is.');
        }
        $keepsCurrency = $account !== null && $account->currency === $data['currency'];
        if (! $keepsCurrency && ! Lookup::query()->where('list', 'currency')->where('code', $data['currency'])->where('active', true)->exists()) {
            $errors['currency'] = __('Choose a currency from the list of currencies.');
        }
        if ($data['ledger_account_id'] !== null) {
            $ledger = Account::query()->find($data['ledger_account_id']);
            if (! $ledger || $ledger->type !== 'asset' || ! $ledger->is_postable || ! $ledger->active) {
                $errors['ledger_account_id'] = __('The ledger account must be an active asset account that can be posted to (not a heading).');
            } elseif ($ledger->currency !== null && $ledger->currency !== $data['currency']) {
                $errors['ledger_account_id'] = __('Ledger account :code is kept in :currency.', ['code' => $ledger->code, 'currency' => $ledger->currency]);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
