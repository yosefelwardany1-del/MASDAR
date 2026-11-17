<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account of the chart (spec §5.6). Headings (is_postable = false) group sub-accounts and are
 * never posted to (trigger). Written only by App\Services\Accounting\ChartOfAccountsService.
 */
class Account extends Model
{
    use HasLocalizedName;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_postable' => 'boolean', 'active' => 'boolean', 'sort' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Assets and expenses grow with debits; the rest with credits. */
    public function isDebitNatured(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    /** @return array<string, string> type => its name in the user's language */
    public static function typeLabels(): array
    {
        return [
            'asset' => __('Asset'), 'liability' => __('Liability'), 'equity' => __('Equity'),
            'income' => __('Income'), 'expense' => __('Expense'),
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? (string) $this->type;
    }

    /** "1401 — Client receivables", in the user's language. */
    public function label(): string
    {
        return $this->code.' — '.$this->displayName();
    }
}
