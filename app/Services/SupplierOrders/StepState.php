<?php

namespace App\Services\SupplierOrders;

use App\Models\SupplierOrderStepEvent;
use Carbon\CarbonImmutable;

/**
 * The state of one step of one supplier order, computed (never stored) from its latest event,
 * its due date and today: done (with the date), skipped, late (past due), due (due within the
 * warning days) or open. Only an open order's steps are ever late or due.
 */
final class StepState
{
    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const LATE = 'late';

    public const DUE = 'due';

    public const OPEN = 'open';

    public function __construct(
        public readonly string $state,
        public readonly ?CarbonImmutable $dueOn,
        public readonly ?SupplierOrderStepEvent $latest,
    ) {}

    /** Done or skipped: nothing more to do for this step. */
    public function isSettled(): bool
    {
        return $this->state === self::DONE || $this->state === self::SKIPPED;
    }

    /** The date the pill shows: when it was done, or when it is due. */
    public function date(): ?CarbonImmutable
    {
        return $this->isSettled() ? $this->latest?->happened_on?->toImmutable() : $this->dueOn;
    }

    /** The CSS modifier of the .app-pill (spec UI conventions), '' for plain open. */
    public function pillClass(): string
    {
        return match ($this->state) {
            self::DONE => 'app-pill--done',
            self::SKIPPED => 'app-pill--skip',
            self::LATE => 'app-pill--late',
            self::DUE => 'app-pill--due',
            default => '',
        };
    }

    public function label(): string
    {
        return match ($this->state) {
            self::DONE => __('Done'),
            self::SKIPPED => __('Not applicable'),
            self::LATE => __('Late'),
            self::DUE => __('Due soon'),
            default => __('Open'),
        };
    }
}
