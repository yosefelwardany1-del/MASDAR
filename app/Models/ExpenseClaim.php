<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * A staff member's expense claim with its receipts (spec §5.8). Its status is its latest
 * decision: none (a draft) → submitted → approved, rejected or returned to the claimant. An
 * approved claim is booked (expense_claim) and paid back with a claim_payment. Written only by
 * App\Services\Finance\ExpenseClaimService.
 */
class ExpenseClaim extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('spent_on')->orderBy('id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ExpenseClaimDecision::class)->orderBy('id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(ExpenseClaimDecision::class)->latestOfMany('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** draft | submitted | approved | rejected | returned */
    public function status(): string
    {
        return $this->latestDecision?->decision ?? 'draft';
    }

    public function isEditable(): bool
    {
        return in_array($this->status(), ['draft', 'returned'], true);
    }

    public function total(): string
    {
        return (string) DB::table('expense_claim_lines')->where('expense_claim_id', $this->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
    }

    public static function statusLabel(string $status): string
    {
        return self::statusText($status)->render();
    }

    /** The status as a Text, for words stored now and read later (B31). */
    public static function statusText(string $status): Text
    {
        return match ($status) {
            'draft' => Text::of('Draft'),
            'submitted' => Text::of('Waiting for approval'),
            'approved' => Text::of('Approved'),
            'rejected' => Text::of('Rejected'),
            'returned' => Text::of('Returned for changes'),
            'paid' => Text::of('Paid back'),
            default => Text::plain($status),
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'submitted' => 'due',
            'approved' => 'info',
            'paid' => 'done',
            'rejected' => 'skip',
            'returned' => 'late',
            default => 'neutral',
        };
    }

    public function attachmentModule(): string
    {
        return 'expense_claims';
    }

    /** Receipts: the claimant's own, or whoever handles claims. */
    public function attachmentAllows(User $user, string $level): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return $level === 'view' || $this->isEditable();
        }

        return $user->can('expense_claims.'.($level === 'delete' ? 'edit' : $level));
    }
}
