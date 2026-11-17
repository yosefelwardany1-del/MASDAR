<?php

namespace App\Services\Finance;

use App\Models\BusinessSetting;
use App\Models\FxRate;
use App\Models\Lookup;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of fx_rates (spec §4, FX): one rate per currency per day, in units of the
 * book currency for one unit of the currency. Where the rates come from is OPEN (Q-A3), so
 * they are typed in; a payment between currencies records the rate actually obtained, and
 * this table only gives its default.
 */
class FxRateService
{
    /** Set the rate of $currency on $date: a new row, or the day's row updated (audited either way). */
    public function set(string $currency, \DateTimeInterface|string $date, string $rate, User $by): FxRate
    {
        $currency = strtoupper(trim($currency));
        $day = CarbonImmutable::parse($date)->toDateString();

        $errors = [];
        if ($currency === BusinessSetting::current()->book_currency) {
            $errors['currency'] = __(':currency is the book currency; its rate is always 1.', ['currency' => $currency]);
        } elseif (! Lookup::query()->where('list', 'currency')->where('code', $currency)->exists()) {
            $errors['currency'] = __('Choose a currency from the list of currencies.');
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,6})?$/', $rate) || bccomp($rate, '0', 6) <= 0) {
            $errors['rate'] = __('The rate is a number greater than zero, with up to 6 decimals.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($currency, $day, $rate, $by) {
            $existing = FxRate::query()->where('currency', $currency)->whereDate('rate_date', $day)->lockForUpdate()->first();
            if ($existing) {
                $old = ['rate' => $existing->rate];
                $existing->update(['rate' => $rate]);
                $existing->refresh();
                AuditTrail::record('updated', $existing, $old, ['currency' => $currency, 'rate_date' => $day, 'rate' => $existing->rate]);

                return $existing;
            }

            $row = FxRate::create(['currency' => $currency, 'rate_date' => $day, 'rate' => $rate, 'created_by' => $by->id]);
            $row->refresh();
            AuditTrail::record('created', $row, [], ['currency' => $currency, 'rate_date' => $day, 'rate' => $row->rate]);

            return $row;
        });
    }

    /**
     * The rate of $currency on $date: the latest one set on or before that day, or null when
     * there is none. The book currency is always '1'.
     */
    public function rateFor(string $currency, \DateTimeInterface|string $date): ?string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === BusinessSetting::current()->book_currency) {
            return '1';
        }

        $rate = FxRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', '<=', CarbonImmutable::parse($date)->toDateString())
            ->orderByDesc('rate_date')
            ->value('rate');

        return $rate === null ? null : (string) $rate;
    }
}
