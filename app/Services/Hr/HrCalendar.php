<?php

namespace App\Services\Hr;

use App\Models\BusinessSetting;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Working days (spec §5.7): the working week (business setting) minus public holidays, plus
 * China's make-up working days on a weekend. Read-only; one instance per use, so the holidays
 * it loads are never stale across requests.
 */
class HrCalendar
{
    /** @var array<string, PublicHoliday> */
    private array $holidays = [];

    /** @var array<int, true> years loaded */
    private array $loaded = [];

    /** @var list<int> */
    private array $workDays;

    public function __construct()
    {
        $this->workDays = BusinessSetting::current()->workDays();
    }

    public function isWorkingDay(CarbonImmutable|string $date): bool
    {
        $date = $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse($date);
        $this->loadYear($date->year);
        $holiday = $this->holidays[$date->toDateString()] ?? null;
        if ($holiday !== null) {
            return $holiday->is_workday;
        }

        return in_array($date->dayOfWeekIso, $this->workDays, true);
    }

    /** The working days from $from to $to, both included. @return list<string> */
    public function workingDays(string $from, string $to): array
    {
        if ($to < $from) {
            return [];
        }
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $day = CarbonImmutable::instance($day);
            if ($this->isWorkingDay($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    public function countWorkingDays(string $from, string $to): int
    {
        return count($this->workingDays($from, $to));
    }

    /** The holiday (or make-up working day) on a date, if any. */
    public function holiday(string $date): ?PublicHoliday
    {
        $this->loadYear((int) substr($date, 0, 4));

        return $this->holidays[$date] ?? null;
    }

    /** A month in the reader's language: "October 2026", "أكتوبر 2026", "2026年10月". */
    public static function monthLabel(CarbonInterface $month, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return str_starts_with($locale, 'zh') ? $month->format('Y').'年'.$month->format('n').'月' : $month->locale($locale)->isoFormat('MMMM YYYY');
    }

    /** False while no public holiday is entered for the year: the sheet and payroll warn (spec §5.7). */
    public static function hasHolidaysFor(int $year): bool
    {
        return PublicHoliday::query()->whereYear('holiday_on', $year)->where('is_workday', false)->exists();
    }

    private function loadYear(int $year): void
    {
        if (isset($this->loaded[$year])) {
            return;
        }
        foreach (PublicHoliday::query()->whereYear('holiday_on', $year)->get() as $holiday) {
            $this->holidays[$holiday->holiday_on->toDateString()] = $holiday;
        }
        $this->loaded[$year] = true;
    }
}
