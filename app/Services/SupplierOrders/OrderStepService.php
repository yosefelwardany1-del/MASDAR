<?php

namespace App\Services\SupplierOrders;

use App\Models\Attachment;
use App\Models\BusinessSetting;
use App\Models\OrderStepDefinition;
use App\Models\Payment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderStepEvent;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of supplier_order_step_events (spec §5.3). A step's state is its latest
 * event: done, skipped, or reopened (= open again). Other modules complete their own steps
 * through recordAutomatic(): shipping marks "booking" and "loading", goods receipt marks
 * "receive_goods". People mark steps done, not applicable (skip) or open again (reopen) on the
 * supplier order's page.
 *
 * Due dates are computed, never stored: due = delivery_date − due_offset_days (null when either
 * is missing). A step of an OPEN order is late when today is past its due date and its latest
 * event is neither done nor skipped, and due soon when its due date is within
 * `step_warning_days` — the same rule as App\Services\Dashboard\AttentionQueries.
 */
class OrderStepService
{
    /**
     * Latest event per step definition id for $order.
     *
     * @return Collection<int, SupplierOrderStepEvent> keyed by step_definition_id
     */
    public function latestEvents(SupplierOrder $order): Collection
    {
        return SupplierOrderStepEvent::query()
            ->where('supplier_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->keyBy('step_definition_id');
    }

    public function isDone(SupplierOrder $order, OrderStepDefinition $step): bool
    {
        $last = $this->latestEvents($order)->get($step->id);

        return $last !== null && in_array($last->status, ['done', 'skipped'], true);
    }

    /**
     * Mark the step with $stepCode done on behalf of another module, unless it already is (or
     * the step was switched off on the Lists & options screen). Returns the new event, or null.
     * $happenedOn defaults to today (e.g. the goods receipt passes the date the goods came in).
     */
    public function recordAutomatic(SupplierOrder $order, string $stepCode, User $by, string $note = '', ?int $paymentId = null, ?string $happenedOn = null): ?SupplierOrderStepEvent
    {
        $step = OrderStepDefinition::where('code', $stepCode)->where('active', true)->first();
        if (! $step || $this->isDone($order, $step)) {
            return null;
        }

        return $this->record($order, $step, 'done', $by, $happenedOn ?? now()->toDateString(), $note ?: null, $paymentId);
    }

    /**
     * Open the step with $stepCode again on behalf of another module when what completed it is
     * undone: the order was unbooked, its goods taken out of a container, a payment reversed.
     * Only a step that is currently DONE reopens; one a person marked not applicable stays so.
     */
    public function reopenAutomatic(SupplierOrder $order, string $stepCode, User $by, string $note = ''): ?SupplierOrderStepEvent
    {
        $step = OrderStepDefinition::where('code', $stepCode)->where('active', true)->first();
        if (! $step || $this->latestEvents($order)->get($step->id)?->status !== 'done') {
            return null;
        }

        return $this->record($order, $step, 'reopened', $by, now()->toDateString(), $note ?: null);
    }

    /** Record one event; the single place a step event is written. */
    public function record(SupplierOrder $order, OrderStepDefinition $step, string $status, User $by, string $happenedOn, ?string $note = null, ?int $paymentId = null): SupplierOrderStepEvent
    {
        return DB::transaction(function () use ($order, $step, $status, $by, $happenedOn, $note, $paymentId) {
            $event = SupplierOrderStepEvent::create([
                'supplier_order_id' => $order->id,
                'step_definition_id' => $step->id,
                'status' => $status,
                'happened_on' => $happenedOn,
                'note' => $note,
                'payment_id' => $paymentId,
                'user_id' => $by->id,
            ]);
            AuditTrail::record('step_'.$status, $order, [], ['step' => $step->code, 'happened_on' => $happenedOn, 'note' => $note]);

            return $event;
        });
    }

    /**
     * A person marks a step done on $happenedOn. A step that requires an attachment needs at
     * least one file; the files are stored on the supplier order in the same transaction. A
     * payment-kind step may name one of this order's payments.
     *
     * @param  UploadedFile|array<int, UploadedFile>|null  $files
     */
    public function markDone(SupplierOrder $order, OrderStepDefinition $step, User $by, string $happenedOn, ?string $note = null, ?int $paymentId = null, UploadedFile|array|null $files = null): SupplierOrderStepEvent
    {
        $files = array_values(array_filter(Arr::wrap($files), fn ($file) => $file instanceof UploadedFile));
        $this->assertChangeable($order, $step);
        $happenedOn = $this->pastOrToday($happenedOn);

        if ($step->requires_attachment && $files === []) {
            throw ValidationException::withMessages(['files' => __('This step needs a document: attach at least one file.')]);
        }
        if ($paymentId !== null) {
            if ($step->kind !== 'payment') {
                throw ValidationException::withMessages(['payment_id' => __('Only a payment step can be linked to a payment.')]);
            }
            if (! Payment::whereKey($paymentId)->where('supplier_order_id', $order->id)->exists()) {
                throw ValidationException::withMessages(['payment_id' => __('Pick a payment of this supplier order.')]);
            }
        }

        /** @var list<Attachment> $stored */
        $stored = [];
        try {
            return DB::transaction(function () use ($order, $step, $by, $happenedOn, $note, $paymentId, $files, &$stored) {
                if ($this->lockedLatest($order, $step)?->status === 'done') {
                    throw ValidationException::withMessages(['step' => __('This step is already done. Reopen it first to record it again.')]);
                }
                $event = $this->record($order, $step, 'done', $by, $happenedOn, $this->clean($note), $paymentId);
                foreach ($files as $file) {
                    $stored[] = app(AttachmentService::class)->store($order, $file, $by->id, mb_substr($step->name.' — '.$file->getClientOriginalName(), 0, 255));
                }

                return $event;
            });
        } catch (\Throwable $e) {
            // The rows rolled back with the transaction; the files on the disk must go too.
            foreach ($stored as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            throw $e;
        }
    }

    /** A person marks a step not applicable to this order (e.g. no artwork for stock goods). */
    public function skip(SupplierOrder $order, OrderStepDefinition $step, User $by, string $reason): SupplierOrderStepEvent
    {
        $this->assertChangeable($order, $step);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($order, $step, $by, $reason) {
            if ($this->lockedLatest($order, $step)?->status === 'skipped') {
                throw ValidationException::withMessages(['step' => __('This step is already marked not applicable.')]);
            }

            return $this->record($order, $step, 'skipped', $by, now()->toDateString(), $reason);
        });
    }

    /** A person opens a done or skipped step again (e.g. the sample was rejected after all). */
    public function reopen(SupplierOrder $order, OrderStepDefinition $step, User $by, string $reason): SupplierOrderStepEvent
    {
        $this->assertChangeable($order, $step);
        $reason = $this->requireReason($reason);

        return DB::transaction(function () use ($order, $step, $by, $reason) {
            $latest = $this->lockedLatest($order, $step);
            if ($latest === null || $latest->status === 'reopened') {
                throw ValidationException::withMessages(['step' => __('This step is already open.')]);
            }

            return $this->record($order, $step, 'reopened', $by, now()->toDateString(), $reason);
        });
    }

    /** The follow-up columns: the active steps in S2's order. */
    public function activeSteps(): Collection
    {
        return OrderStepDefinition::query()->where('active', true)->ordered()->get();
    }

    /** delivery_date − due_offset_days, or null when the order has no delivery date or the step no offset. */
    public function dueDateFor(SupplierOrder $order, OrderStepDefinition $step): ?CarbonImmutable
    {
        if ($order->delivery_date === null || $step->due_offset_days === null) {
            return null;
        }

        return $order->delivery_date->toImmutable()->startOfDay()->subDays($step->due_offset_days);
    }

    /** The state of one step, given its latest event (null when nothing was recorded yet). */
    public function stateFor(SupplierOrder $order, OrderStepDefinition $step, ?SupplierOrderStepEvent $latest, ?CarbonImmutable $today = null, ?int $warningDays = null): StepState
    {
        $due = $this->dueDateFor($order, $step);

        if ($latest?->status === 'done') {
            return new StepState(StepState::DONE, $due, $latest);
        }
        if ($latest?->status === 'skipped') {
            return new StepState(StepState::SKIPPED, $due, $latest);
        }
        if ($order->status !== 'open' || $due === null) {
            return new StepState(StepState::OPEN, $due, $latest);
        }

        $today ??= CarbonImmutable::today();
        $warningDays ??= BusinessSetting::current()->step_warning_days;

        return match (true) {
            $due->lessThan($today) => new StepState(StepState::LATE, $due, $latest),
            $due->lessThanOrEqualTo($today->addDays($warningDays)) => new StepState(StepState::DUE, $due, $latest),
            default => new StepState(StepState::OPEN, $due, $latest),
        };
    }

    /**
     * Every step's state for one order.
     *
     * @return Collection<int, StepState> keyed by step definition id
     */
    public function statesFor(SupplierOrder $order, ?Collection $steps = null): Collection
    {
        return collect($this->boardStates(collect([$order]), $steps)[$order->id] ?? []);
    }

    /**
     * The board: every step's state for every order given, from ONE query of their events.
     *
     * @param  Collection<int, SupplierOrder>  $orders
     * @return array<int, array<int, StepState>> order id => step definition id => state
     */
    public function boardStates(Collection $orders, ?Collection $steps = null): array
    {
        $steps ??= $this->activeSteps();
        $today = CarbonImmutable::today();
        $warningDays = BusinessSetting::current()->step_warning_days;

        $latest = SupplierOrderStepEvent::query()
            ->whereIn('supplier_order_id', $orders->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('supplier_order_id')
            ->map(fn (Collection $events) => $events->keyBy('step_definition_id'));

        $board = [];
        foreach ($orders as $order) {
            foreach ($steps as $step) {
                $board[$order->id][$step->id] = $this->stateFor($order, $step, $latest->get($order->id)?->get($step->id), $today, $warningDays);
            }
        }

        return $board;
    }

    /** A cancelled order's steps stay as they were; a switched-off step takes no new events. */
    private function assertChangeable(SupplierOrder $order, OrderStepDefinition $step): void
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['step' => __('This order is cancelled. Reopen it to record its steps.')]);
        }
        if (! $step->active) {
            throw ValidationException::withMessages(['step' => __('This step is switched off on the Lists & options screen.')]);
        }
    }

    /** The latest event of the step, read after locking the order so two people queue up. */
    private function lockedLatest(SupplierOrder $order, OrderStepDefinition $step): ?SupplierOrderStepEvent
    {
        SupplierOrder::query()->whereKey($order->id)->lockForUpdate()->first();

        return SupplierOrderStepEvent::query()
            ->where('supplier_order_id', $order->id)
            ->where('step_definition_id', $step->id)
            ->orderByDesc('id')
            ->first();
    }

    private function pastOrToday(string $date): string
    {
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['happened_on' => __('Enter a valid date.')]);
        }
        if ($day->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['happened_on' => __('A step cannot be done on a future date.')]);
        }

        return $day->toDateString();
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason.')]);
        }

        return $reason;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
