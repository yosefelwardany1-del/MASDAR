<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\PostingRule;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of accounts and posting_rules (spec §5.6, §3.6). The chart MASDAR starts
 * with is a draft for its accountant (Q-A4), so every account and rule is editable here, within
 * what keeps the ledger true:
 *  - an account's code never changes (the reference seeder re-adds a missing code on every
 *    deploy, and people know accounts by code); accounts are switched off, never deleted;
 *  - a sub-account sits under a heading of the same type, never under itself or its own child;
 *  - an account with postings never becomes a heading and never changes type, and a heading
 *    with sub-accounts never becomes postable;
 *  - an account limited to one currency holds only lines in that currency.
 */
class ChartOfAccountsService
{
    private const FIELDS = ['code', 'name', 'name_ar', 'name_zh', 'type', 'parent_id', 'is_postable', 'currency', 'active', 'sort'];

    /** @param array<string, mixed> $data validated: code, name, name_ar?, name_zh?, type, parent_id?, is_postable, currency?, active, sort? */
    public function create(array $data, User $by): Account
    {
        $this->authorize($by);
        $data = $this->clean($data);
        if (Account::query()->where('code', $data['code'])->exists()) {
            throw new AccountingException(__('Account code :code is taken.', ['code' => $data['code']]));
        }
        $this->assertParent(null, $data['parent_id'], $data['type']);

        return DB::transaction(function () use ($data) {
            $account = Account::create($data + ['sort' => (int) Account::query()->max('sort') + 1]);
            AuditTrail::record('created', $account, [], $account->only(self::FIELDS));

            return $account;
        });
    }

    /** @param array<string, mixed> $data validated: as create, without code */
    public function update(Account $account, array $data, User $by): Account
    {
        $this->authorize($by);
        unset($data['code']);
        $data = $this->clean($data + ['code' => $account->code]);
        unset($data['code']);

        $hasLines = $account->lines()->exists();
        $hasChildren = $account->children()->exists();

        if ($data['type'] !== $account->type && ($hasLines || $hasChildren)) {
            throw new AccountingException($hasLines
                ? __('Account :code has postings; its type cannot change.', ['code' => $account->code])
                : __('Account :code has sub-accounts; its type cannot change.', ['code' => $account->code]));
        }
        if ($account->is_postable && ! $data['is_postable'] && $hasLines) {
            throw new AccountingException(__('Account :code has postings; it cannot become a heading.', ['code' => $account->code]));
        }
        if (! $account->is_postable && $data['is_postable'] && $hasChildren) {
            throw new AccountingException(__('Account :code has sub-accounts; it stays a heading.', ['code' => $account->code]));
        }
        if ($data['currency'] !== null && $hasLines
            && $account->lines()->where('currency', '<>', $data['currency'])->exists()) {
            throw new AccountingException(__('Account :code has postings in other currencies; it cannot be limited to :currency.', [
                'code' => $account->code, 'currency' => $data['currency'],
            ]));
        }
        $this->assertParent($account, $data['parent_id'], $data['type']);

        return DB::transaction(function () use ($account, $data) {
            $old = $account->only(array_keys($data));
            $account->update($data);
            AuditTrail::record('updated', $account, $old, $account->only(array_keys($data)));

            return $account;
        });
    }

    /**
     * Point a posting rule at its accounts. Only the sides the rule sets are kept (for a
     * payment the other side is the money account's own ledger account).
     *
     * @param  array{debit_account_id?: ?int, credit_account_id?: ?int, description?: ?string}  $data
     */
    public function updatePostingRule(PostingRule $rule, array $data, User $by): PostingRule
    {
        $this->authorize($by);
        $sides = PostingService::RULE_SIDES[$rule->event] ?? ['debit', 'credit'];
        $changes = ['description' => $data['description'] ?? $rule->description];

        foreach (['debit', 'credit'] as $side) {
            $id = in_array($side, $sides, true) && filled($data["{$side}_account_id"] ?? null) ? (int) $data["{$side}_account_id"] : null;
            if ($id !== null) {
                $account = Account::query()->find($id);
                if (! $account || ! $account->is_postable || ! $account->active) {
                    throw new AccountingException(__('Posting rules point at active accounts that take postings, not headings.'));
                }
            }
            $changes["{$side}_account_id"] = in_array($side, $sides, true) ? $id : null;
        }
        // B29: a rule that debits and credits the same account would post entries that move nothing.
        if ($changes['debit_account_id'] !== null && $changes['debit_account_id'] === $changes['credit_account_id']) {
            throw new AccountingException(__('A posting rule debits one account and credits another: choose two different accounts.'));
        }

        return DB::transaction(function () use ($rule, $changes) {
            $old = $rule->only(array_keys($changes));
            $rule->update($changes);
            AuditTrail::record('updated', $rule, $old, $rule->only(array_keys($changes)));

            return $rule;
        });
    }

    /** @return array<string, mixed> */
    private function clean(array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, Account::TYPES, true)) {
            throw new AccountingException(__('Choose the type of the account.'));
        }

        return array_filter([
            'code' => trim((string) ($data['code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'name_ar' => filled($data['name_ar'] ?? null) ? trim($data['name_ar']) : null,
            'name_zh' => filled($data['name_zh'] ?? null) ? trim($data['name_zh']) : null,
            'type' => $type,
            'parent_id' => filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
            'is_postable' => (bool) ($data['is_postable'] ?? false),
            'currency' => filled($data['currency'] ?? null) ? strtoupper($data['currency']) : null,
            'active' => (bool) ($data['active'] ?? true),
            'sort' => isset($data['sort']) && $data['sort'] !== '' ? (int) $data['sort'] : null,
        ], fn ($value, $key) => $key !== 'sort' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private function assertParent(?Account $account, ?int $parentId, string $type): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = Account::query()->find($parentId);
        if (! $parent) {
            throw new AccountingException(__('Choose the heading the account sits under.'));
        }
        if ($parent->is_postable) {
            throw new AccountingException(__('Account :code takes postings; a sub-account sits under a heading.', ['code' => $parent->code]));
        }
        if ($parent->type !== $type) {
            throw new AccountingException(__('A sub-account has the type of its heading (:code is :type).', ['code' => $parent->code, 'type' => $parent->typeLabel()]));
        }
        if ($account) {
            for ($node = $parent; $node; $node = $node->parent_id ? Account::query()->find($node->parent_id) : null) {
                if ($node->id === $account->id) {
                    throw new AccountingException(__('An account cannot sit under itself or one of its own sub-accounts.'));
                }
            }
        }
    }

    private function authorize(User $by): void
    {
        if (! $by->can('accounting.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
