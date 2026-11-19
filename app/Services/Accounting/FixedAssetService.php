<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\DepreciationLine;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of fixed_assets and depreciation_lines (spec §5.8). An asset is depreciated
 * straight-line, a full month's charge from its start month, never below its residual value and
 * not after it is disposed of. A month is depreciated once, in one journal entry
 * (PostingService::postDepreciation) whose lines are recorded per asset here, in the same
 * transaction. An asset's cost, life and accounts change only until it has been depreciated.
 */
class FixedAssetService
{
    public const FINANCIAL = ['acquired_on', 'cost', 'residual_value', 'useful_life_months', 'depreciation_starts_on', 'asset_account_id', 'depreciation_account_id', 'expense_account_id'];

    public function __construct(private NumberingService $numbers, private PostingService $posting) {}

    public function create(array $data, User $by): FixedAsset
    {
        $this->authorize($by);
        $values = $this->values($data, null);

        return DB::transaction(function () use ($values, $by) {
            $asset = FixedAsset::create($values + ['code' => $this->numbers->next('asset'), 'created_by' => $by->id]);
            AuditTrail::record('created', $asset, [], $values);

            return $asset;
        });
    }

    public function update(FixedAsset $asset, array $data, User $by): FixedAsset
    {
        $this->authorize($by);
        $values = $this->values($data, $asset);
        if ($asset->depreciation()->exists()) {
            foreach (self::FINANCIAL as $field) {
                $before = $asset->{$field} instanceof \DateTimeInterface ? $asset->{$field}->format('Y-m-d') : (string) $asset->{$field};
                if ((string) $values[$field] !== $before && ! (is_numeric($values[$field]) && is_numeric($before) && bccomp((string) $values[$field], $before, 4) === 0)) {
                    throw ValidationException::withMessages([$field => __('The asset is already depreciated: its cost, life, dates and accounts no longer change.')]);
                }
            }
        }

        return DB::transaction(function () use ($asset, $values) {
            $old = $asset->only(array_keys($values));
            $asset->update($values);
            AuditTrail::record('updated', $asset, $old, $values);

            return $asset;
        });
    }

    public function dispose(FixedAsset $asset, string $on, string $note, User $by): FixedAsset
    {
        $this->authorize($by);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on < $asset->acquired_on->toDateString()) {
            throw ValidationException::withMessages(['disposed_on' => __('Enter the date it left, on or after it was bought.')]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['disposal_note' => __('Say how it left: sold, scrapped, lost.')]);
        }

        return DB::transaction(function () use ($asset, $on, $note) {
            $asset->update(['disposed_on' => $on, 'disposal_note' => mb_substr(trim($note), 0, 2000)]);
            AuditTrail::record('disposed', $asset, [], ['disposed_on' => $on, 'note' => $note, 'book_value' => $asset->bookValue()]);

            return $asset;
        });
    }

    /**
     * B19: the one month $asset may be depreciated for next — the month after its last posted one,
     * or its first month — as Y-m; null once fully depreciated, or disposed before that month.
     */
    public function nextMonth(FixedAsset $asset): ?string
    {
        $last = DB::table('depreciation_lines')->where('fixed_asset_id', $asset->id)->max('month');
        $next = $last ? Carbon::parse($last)->startOfMonth()->addMonthNoOverflow() : Carbon::parse($asset->depreciation_starts_on)->startOfMonth();
        $left = bcsub(bcsub((string) $asset->cost, (string) $asset->residual_value, 4), $asset->depreciated(), 4);
        if (bccomp($left, '0', 4) <= 0 || ($asset->disposed_on && Carbon::parse($asset->disposed_on)->lt($next))) {
            return null;
        }

        return $next->format('Y-m');
    }

    /**
     * B19: months that have begun and are not posted yet, oldest first: from the asset's next
     * month up to this month (Y-m each), for the screen to list.
     *
     * @return list<string>
     */
    public function missingMonths(FixedAsset $asset): array
    {
        $next = $this->nextMonth($asset);
        if ($next === null) {
            return [];
        }
        $months = [];
        $at = Carbon::parse($next.'-01');
        $now = now()->startOfMonth();
        while ($at->lte($now) && count($months) < 120) {
            $months[] = $at->format('Y-m');
            $at->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * What depreciating $month would charge: each asset whose next month it is, and its amount
     * (B19: month by month — an asset with earlier months to post is not charged for a later one).
     *
     * @return Collection<int, array{0: FixedAsset, 1: string}>
     */
    public function charges(string $month): Collection
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return FixedAsset::query()
            ->whereDate('depreciation_starts_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('disposed_on')->orWhereDate('disposed_on', '>=', $start->toDateString()))
            ->whereDoesntHave('depreciation', fn ($q) => $q->whereDate('month', $start->toDateString()))
            ->orderBy('code')->get()
            ->filter(fn (FixedAsset $asset) => $this->nextMonth($asset) === $month)
            ->map(function (FixedAsset $asset) {
                $left = bcsub(bcsub((string) $asset->cost, (string) $asset->residual_value, 4), $asset->depreciated(), 4);
                $charge = bccomp($asset->monthlyCharge(), $left, 4) > 0 ? $left : $asset->monthlyCharge();

                return [$asset, $charge];
            })
            ->filter(fn ($c) => bccomp($c[1], '0', 4) > 0)->values();
    }

    public function depreciate(string $month, User $by): JournalEntry
    {
        $this->authorize($by);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages(['month' => __('Choose a month.')]);
        }
        OpenPeriods::assert(CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString(), 'month');
        // B19: month by month, and never a month that has not begun.
        if ($month > now()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => __(':month has not begun: depreciation is posted for this month or earlier.', ['month' => $month])]);
        }
        $behind = FixedAsset::query()->whereNull('disposed_on')->orderBy('code')->get()
            ->filter(fn (FixedAsset $a) => ($next = $this->nextMonth($a)) !== null && $next < $month);
        if ($behind->isNotEmpty()) {
            throw ValidationException::withMessages(['month' => __('Depreciation goes month by month: post :first first (:assets).', [
                'first' => $behind->map(fn ($a) => $this->nextMonth($a))->min(), 'assets' => $behind->pluck('code')->implode(', '),
            ])]);
        }
        $charges = $this->charges($month);
        if ($charges->isEmpty()) {
            throw ValidationException::withMessages(['month' => __('Nothing to depreciate for :month.', ['month' => $month])]);
        }

        return DB::transaction(function () use ($month, $charges, $by) {
            $entry = $this->posting->postDepreciation($month.'-01', $charges->all(), $by);
            foreach ($charges as [$asset, $amount]) {
                DepreciationLine::create(['fixed_asset_id' => $asset->id, 'journal_entry_id' => $entry->id, 'month' => $month.'-01', 'amount' => $amount]);
            }

            return $entry;
        });
    }

    private function values(array $data, ?FixedAsset $asset): array
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = __('Name the asset.');
        }
        foreach (['acquired_on', 'depreciation_starts_on'] as $field) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data[$field] ?? ''))) {
                $errors[$field] = __('Enter a valid date.');
            }
        }
        // B20: an asset is bought on a day that has come.
        if (! isset($errors['acquired_on']) && $data['acquired_on'] > now()->toDateString()) {
            $errors['acquired_on'] = __('The purchase date cannot be in the future.');
        }
        $life = (string) ($data['useful_life_months'] ?? '');
        if (! ctype_digit($life) || (int) $life < 1 || (int) $life > 600) {
            $errors['useful_life_months'] = __('Enter its life in months, 1 to 600.');
        }
        // B20: each account is one of its kind — a fixed-asset account, the accumulated-depreciation
        // account, an expense account — never a bank or a receivable. An asset keeps what it has.
        $accounts = [];
        $messages = [
            'asset_account_id' => __('Choose a fixed-asset account (under :heading), not a bank or a receivable.', ['heading' => $this->fixedAssetHeading()?->code ?? '18']),
            'depreciation_account_id' => __('Choose the accumulated-depreciation account.'),
            'expense_account_id' => __('Choose an expense account that takes postings.'),
        ];
        foreach ($messages as $field => $message) {
            $id = (int) ($data[$field] ?? 0);
            $allowed = $this->accountChoices($field)->pluck('id')->all();
            if (! in_array($id, $allowed, true) && ! ($asset && (int) $asset->{$field} === $id)) {
                $errors[$field] = $message;
            }
            $accounts[$field] = $id ?: null;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $cost = Money::amount($data['cost'] ?? null, 'cost', true, decimals: 2);
        $residual = Money::amount($data['residual_value'] ?? '0', 'residual_value', decimals: 2);
        if (bccomp($residual, $cost, 4) >= 0) {
            throw ValidationException::withMessages(['residual_value' => __('The residual value is less than the cost.')]);
        }
        if (Carbon::parse($data['depreciation_starts_on'])->startOfMonth()->lt(Carbon::parse($data['acquired_on'])->startOfMonth())) {
            throw ValidationException::withMessages(['depreciation_starts_on' => __('Depreciation starts on or after the purchase.')]);
        }

        return [
            'name' => mb_substr($name, 0, 160),
            'acquired_on' => $data['acquired_on'], 'depreciation_starts_on' => Carbon::parse($data['depreciation_starts_on'])->startOfMonth()->toDateString(),
            'cost' => $cost, 'residual_value' => $residual, 'useful_life_months' => (int) $life,
            'location' => filled($data['location'] ?? null) ? mb_substr(trim((string) $data['location']), 0, 120) : null,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 2000) : null,
        ] + $accounts;
    }

    /** The heading the fixed-asset accounts sit under: the parent of the depreciation rule's credit account, else code 18. */
    public function fixedAssetHeading(): ?Account
    {
        $contra = $this->contraAccountId();
        $parent = $contra ? Account::query()->whereKey($contra)->value('parent_id') : null;

        return ($parent ? Account::query()->find($parent) : null) ?? Account::query()->where('code', '18')->first();
    }

    /**
     * B20: the accounts a field may take. asset_account_id: postable asset accounts under the
     * fixed-asset heading, the accumulated-depreciation account aside; depreciation_account_id: that
     * account (the depreciation rule's credit); expense_account_id: postable expense accounts.
     *
     * @return Collection<int, Account>
     */
    public function accountChoices(string $field): Collection
    {
        $postable = Account::query()->where('is_postable', true)->where('active', true)->orderBy('code');

        return match ($field) {
            'asset_account_id' => (function () use ($postable) {
                $heading = $this->fixedAssetHeading();
                if (! $heading) {
                    return collect();
                }
                $ids = collect(DB::select('WITH RECURSIVE t AS (SELECT id FROM accounts WHERE parent_id = ? UNION ALL SELECT a.id FROM accounts a JOIN t ON a.parent_id = t.id) SELECT id FROM t', [$heading->id]))->pluck('id');

                return $postable->where('type', 'asset')->whereIn('id', $ids)->where('id', '<>', (int) $this->contraAccountId())->get();
            })(),
            'depreciation_account_id' => $postable->whereKey((int) $this->contraAccountId())->get(),
            default => $postable->where('type', 'expense')->get(),
        };
    }

    private function contraAccountId(): ?int
    {
        $id = DB::table('posting_rules')->where('event', 'depreciation')->value('credit_account_id')
            ?? Account::query()->where('code', '1802')->value('id');

        return $id ? (int) $id : null;
    }

    private function authorize(User $by): void
    {
        if (! $by->can('fixed_assets.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
