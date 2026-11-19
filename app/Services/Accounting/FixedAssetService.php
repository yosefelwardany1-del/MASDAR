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

    /** What depreciating $month would charge: each asset and its amount. @return Collection<int, array{0: FixedAsset, 1: string}> */
    public function charges(string $month): Collection
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return FixedAsset::query()
            ->whereDate('depreciation_starts_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('disposed_on')->orWhereDate('disposed_on', '>=', $start->toDateString()))
            ->whereDoesntHave('depreciation', fn ($q) => $q->whereDate('month', $start->toDateString()))
            ->orderBy('code')->get()
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
        $life = (string) ($data['useful_life_months'] ?? '');
        if (! ctype_digit($life) || (int) $life < 1 || (int) $life > 600) {
            $errors['useful_life_months'] = __('Enter its life in months, 1 to 600.');
        }
        $accounts = [];
        foreach (['asset_account_id' => 'asset', 'depreciation_account_id' => 'asset', 'expense_account_id' => 'expense'] as $field => $type) {
            $account = Account::query()->whereKey((int) ($data[$field] ?? 0))->where('type', $type)->where('is_postable', true)->first();
            if (! $account) {
                $errors[$field] = $type === 'asset' ? __('Choose an asset account that takes postings.') : __('Choose an expense account that takes postings.');
            }
            $accounts[$field] = $account?->id;
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

    private function authorize(User $by): void
    {
        if (! $by->can('fixed_assets.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
