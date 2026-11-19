<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use Illuminate\Validation\ValidationException;

/**
 * B18: a dated document is refused when it is entered — not only when it is posted — if its date
 * falls in a closed accounting period: payments and transfers, notes and service invoices when
 * issued, expense receipts, the payroll month, the depreciation month. The message goes to the
 * date's field. The triggers hold the same rule (in_closed_period).
 */
final class OpenPeriods
{
    public static function closedOn(string $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()->whereNotNull('closed_at')
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
    }

    public static function assert(string $date, string $field): void
    {
        if ($period = self::closedOn($date)) {
            throw ValidationException::withMessages([$field => __('The period :period (:from – :to) is closed. Choose a date in an open period, or ask the accountant to reopen it.', [
                'period' => $period->name, 'from' => $period->starts_on->toDateString(), 'to' => $period->ends_on->toDateString(),
            ])]);
        }
    }
}
