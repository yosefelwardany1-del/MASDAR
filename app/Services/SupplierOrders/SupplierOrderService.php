<?php

namespace App\Services\SupplierOrders;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderDateChange;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of supplier_orders, supplier_order_lines and supplier_order_date_changes
 * (spec §5.3). A supplier order is one factory's part of a client order: its lines are PI lines
 * at the supplier's price, taken from an issued (accepted, confirmed) PI. A PI line is ordered
 * once across its revisions (trigger). The promised delivery date is never overwritten without a
 * date-change row, so slippage can be measured. Every write is audited in its transaction.
 */
class SupplierOrderService
{
    /** A PI's lines can be ordered once the client has it (spec §5.2 workflow). */
    public const PI_STATUSES = ['issued', 'accepted', 'confirmed'];

    /** The header fields a person edits (the delivery date and merchandiser have their own writers). */
    public const HEADER_FIELDS = ['confirmation_date', 'currency', 'deposit_percent', 'payment_terms', 'notes'];

    public function __construct(private NumberingService $numbers) {}

    /**
     * Every PI line id that is on a supplier order or is another revision of one that is (its
     * source_line_id ancestors and descendants). SQL to use inside NOT IN (...).
     */
    public static function blockedLineIdsSql(): string
    {
        return <<<'SQL'
            WITH RECURSIVE up AS (
                SELECT sol.pi_line_id AS id FROM supplier_order_lines sol WHERE sol.released_at IS NULL
                UNION
                SELECT p.source_line_id FROM pi_lines p JOIN up ON p.id = up.id WHERE p.source_line_id IS NOT NULL
            ), down AS (
                SELECT sol.pi_line_id AS id FROM supplier_order_lines sol WHERE sol.released_at IS NULL
                UNION
                SELECT p.id FROM pi_lines p JOIN down ON p.source_line_id = down.id
            )
            SELECT id FROM up UNION SELECT id FROM down
        SQL;
    }

    /** The lines of $pi not yet on any supplier order, in PI order. */
    public function unassignedLines(ProformaInvoice $pi): Collection
    {
        return PiLine::query()
            ->where('proforma_invoice_id', $pi->id)
            ->whereRaw('pi_lines.id NOT IN ('.self::blockedLineIdsSql().')')
            ->with(['product', 'supplier'])
            ->orderBy('line_no')
            ->get();
    }

    /** PIs that can be ordered from and still have lines not on a supplier order. */
    public function piQueryWithUnassignedLines(): Builder
    {
        return ProformaInvoice::query()
            ->whereIn('status', self::PI_STATUSES)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pi_lines')
                ->whereColumn('pi_lines.proforma_invoice_id', 'proforma_invoices.id')
                ->whereRaw('pi_lines.id NOT IN ('.self::blockedLineIdsSql().')'));
    }

    /**
     * One supplier order per chosen supplier from $pi's unassigned lines. Each group:
     * supplier_id, lines (pi_line_id => unit price, or null for the PI line's supplier price),
     * and the header: confirmation_date, delivery_date, currency, deposit_percent, payment_terms,
     * merchandiser_id, notes. Groups naming the same supplier become one order (the first
     * group's header). Orders are numbered in the PI's line order.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return Collection<int, SupplierOrder>
     */
    public function createFromPi(ProformaInvoice $pi, array $groups, User $by): Collection
    {
        return DB::transaction(function () use ($pi, $groups, $by) {
            $pi = $this->lockOrderablePi($pi);
            $available = $this->unassignedLines($pi)->keyBy('id');

            $bySupplier = [];
            foreach ($groups as $group) {
                $lines = $group['lines'] ?? [];
                if ($lines === []) {
                    continue;
                }
                $supplierId = (int) ($group['supplier_id'] ?? 0);
                if (! isset($bySupplier[$supplierId])) {
                    $bySupplier[$supplierId] = $group + ['lines' => []];
                    $bySupplier[$supplierId]['lines'] = [];
                }
                $bySupplier[$supplierId]['lines'] += $lines;
            }
            if ($bySupplier === []) {
                throw ValidationException::withMessages(['groups' => __('Pick at least one line to order.')]);
            }

            $prepared = [];
            foreach ($bySupplier as $supplierId => $group) {
                $supplier = $this->supplier($supplierId);
                $lines = $this->priceLines($group['lines'], $available);
                $prepared[] = [$supplier, $group, $lines, $lines->min(fn (array $l) => $l['line']->line_no)];
            }
            usort($prepared, fn ($a, $b) => $a[3] <=> $b[3]);

            $orders = collect();
            foreach ($prepared as [$supplier, $group, $lines]) {
                $order = SupplierOrder::create([
                    'number' => $this->numbers->next('supplier_order'),
                    'supplier_id' => $supplier->id,
                    'status' => 'open',
                    'confirmation_date' => $group['confirmation_date'] ?? null,
                    'delivery_date' => $group['delivery_date'] ?? null,
                    'currency' => $group['currency'] ?? BusinessSetting::current()->supplier_currency,
                    'deposit_percent' => $this->blankToNull($group['deposit_percent'] ?? null),
                    'payment_terms' => $this->blankToNull($group['payment_terms'] ?? null),
                    'merchandiser_id' => $this->merchandiser($group['merchandiser_id'] ?? null)?->id ?? $by->id,
                    'notes' => $this->blankToNull($group['notes'] ?? null),
                    'created_by' => $by->id,
                ]);
                $added = $this->createLines($order, $lines);
                AuditTrail::record('created', $order, [], $order->only([
                    'number', 'supplier_id', 'confirmation_date', 'delivery_date', 'currency', 'deposit_percent', 'payment_terms', 'merchandiser_id',
                ]) + ['proforma_invoice' => $pi->reference(), 'lines' => $added]);
                $orders->push($order);
            }

            return $orders;
        });
    }

    /**
     * Add unassigned lines of $pi to an open order.
     *
     * @param  array<int|string, string|null>  $lines  pi_line_id => unit price (null: the PI line's supplier price)
     */
    public function addLinesFromPi(SupplierOrder $order, ProformaInvoice $pi, array $lines, User $by): SupplierOrder
    {
        return DB::transaction(function () use ($order, $pi, $lines, $by) {
            $order = $this->lockOpen($order);
            $pi = $this->lockOrderablePi($pi);
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => __('Pick at least one line to order.')]);
            }
            $added = $this->createLines($order, $this->priceLines($lines, $this->unassignedLines($pi)->keyBy('id')));
            AuditTrail::record('lines_added', $order, [], ['proforma_invoice' => $pi->reference(), 'lines' => $added, 'by' => $by->id]);

            return $order->refresh();
        });
    }

    /** @param  array<string, mixed>  $data  any of HEADER_FIELDS */
    public function updateHeader(SupplierOrder $order, array $data, User $by): SupplierOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockOpen($order);
            $fields = array_values(array_intersect(self::HEADER_FIELDS, array_keys($data)));
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $this->blankToNull($data[$field]);
            }
            if (array_key_exists('currency', $values) && $values['currency'] === null) {
                throw ValidationException::withMessages(['currency' => __('Pick a currency.')]);
            }

            $old = $order->only($fields);
            $order->update($values);
            AuditTrail::record('updated', $order, $old, $order->only($fields));

            return $order;
        });
    }

    /**
     * The supplier promised a new delivery date: a date-change row (old, new, reason) and the
     * header, in one transaction. The order's merchandiser and its clients' account managers
     * are told (spec §7, "Delivery date moved").
     */
    public function changeDeliveryDate(SupplierOrder $order, string $newDate, string $reason, User $by): SupplierOrderDateChange
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason.')]);
        }
        try {
            $newDate = CarbonImmutable::parse($newDate)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['delivery_date' => __('Enter a valid date.')]);
        }

        $change = DB::transaction(function () use ($order, $newDate, $reason, $by) {
            $order = $this->lockOpen($order);
            $oldDate = $order->delivery_date?->toDateString();
            if ($oldDate === $newDate) {
                throw ValidationException::withMessages(['delivery_date' => __('That is already the delivery date.')]);
            }

            $change = SupplierOrderDateChange::create([
                'supplier_order_id' => $order->id,
                'old_date' => $oldDate,
                'new_date' => $newDate,
                'reason' => $reason,
                'user_id' => $by->id,
            ]);
            $order->update(['delivery_date' => $newDate]);
            AuditTrail::record('delivery_date_changed', $order, ['delivery_date' => $oldDate], ['delivery_date' => $newDate, 'reason' => $reason]);

            return $change;
        });

        $this->notifyDateMoved($order->refresh(), $change, $by);

        return $change;
    }

    public function assignMerchandiser(SupplierOrder $order, ?User $merchandiser, User $by): SupplierOrder
    {
        $this->merchandiser($merchandiser?->id);

        return DB::transaction(function () use ($order, $merchandiser) {
            $order = $this->lockOpen($order);
            $old = $order->merchandiser_id;
            $order->update(['merchandiser_id' => $merchandiser?->id]);
            AuditTrail::record('merchandiser_assigned', $order, ['merchandiser_id' => $old], ['merchandiser_id' => $merchandiser?->id]);

            return $order;
        });
    }

    public function complete(SupplierOrder $order, User $by, ?string $note = null): SupplierOrder
    {
        return $this->moveStatus($order, ['open'], 'completed', 'completed', $note);
    }

    /**
     * Cancelled while nothing has been received for it; received goods are corrected first.
     * Its PI lines are released in the same transaction, so they can be ordered from another
     * factory; the cancelled order keeps them in its history.
     */
    public function cancel(SupplierOrder $order, User $by, string $reason): SupplierOrder
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Give a reason.')]);
        }

        return DB::transaction(function () use ($order, $reason) {
            $order = $this->moveStatus($order, ['open'], 'cancelled', 'cancelled', $reason, function (SupplierOrder $order) {
                $received = DB::table('goods_receipts')
                    ->join('supplier_order_lines', 'supplier_order_lines.id', '=', 'goods_receipts.supplier_order_line_id')
                    ->where('supplier_order_lines.supplier_order_id', $order->id)
                    ->sum('goods_receipts.cartons');
                if (bccomp((string) $received, '0', 4) > 0) {
                    throw ValidationException::withMessages(['status' => __('Goods have been received for this order: complete it instead, or correct the receipts first.')]);
                }
            });
            $released = SupplierOrderLine::query()->where('supplier_order_id', $order->id)->whereNull('released_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goods_receipts')->whereColumn('goods_receipts.supplier_order_line_id', 'supplier_order_lines.id'))
                ->update(['released_at' => now()]);
            if ($released > 0) {
                AuditTrail::record('lines_released', $order, [], ['lines' => $released]);
            }

            return $order;
        });
    }

    /**
     * Opens a completed or cancelled order again. A cancelled order takes its released lines
     * back — refused when one of them has since been ordered from another factory.
     */
    public function reopen(SupplierOrder $order, User $by, ?string $reason = null): SupplierOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->moveStatus($order, ['completed', 'cancelled'], 'open', 'reopened', $reason);
            try {
                $taken = SupplierOrderLine::query()->where('supplier_order_id', $order->id)->whereNotNull('released_at')->update(['released_at' => null]);
            } catch (QueryException $e) {
                if (in_array($e->getCode(), ['23505'], true)) {
                    throw ValidationException::withMessages(['status' => __('A line of this order has since been ordered from another supplier, so the order cannot be reopened. Create a new order instead.')]);
                }
                throw $e;
            }
            if ($taken > 0) {
                AuditTrail::record('lines_taken_back', $order, [], ['lines' => $taken]);
            }

            return $order;
        });
    }

    /** @param  list<string>  $from */
    private function moveStatus(SupplierOrder $order, array $from, string $to, string $action, ?string $reason, ?callable $guard = null): SupplierOrder
    {
        return DB::transaction(function () use ($order, $from, $to, $action, $reason, $guard) {
            $order = SupplierOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, $from, true)) {
                throw ValidationException::withMessages(['status' => __('This order cannot move from :from to :to.', [
                    'from' => self::statusLabel($order->status), 'to' => self::statusLabel($to),
                ])]);
            }
            if ($guard) {
                $guard($order);
            }
            $old = $order->status;
            $order->update(['status' => $to]);
            AuditTrail::record($action, $order, ['status' => $old], ['status' => $to, 'reason' => $this->blankToNull($reason)]);

            return $order;
        });
    }

    /** The PI, locked, if its lines can be ordered. */
    private function lockOrderablePi(ProformaInvoice $pi): ProformaInvoice
    {
        $pi = ProformaInvoice::query()->whereKey($pi->id)->lockForUpdate()->firstOrFail();
        if (! in_array($pi->status, self::PI_STATUSES, true)) {
            throw ValidationException::withMessages(['pi' => __('Only an issued, accepted or confirmed PI can be ordered from.')]);
        }

        return $pi;
    }

    private function lockOpen(SupplierOrder $order): SupplierOrder
    {
        $order = SupplierOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'open') {
            throw ValidationException::withMessages(['status' => __('This order is not open. Reopen it to change it.')]);
        }

        return $order;
    }

    /** An order status in the user's language. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => __('Open'),
            'completed' => __('Completed'),
            'cancelled' => __('Cancelled'),
            default => $status,
        };
    }

    /** The merchandiser to follow an order: someone who may edit supplier orders. */
    private function merchandiser(int|string|null $userId): ?User
    {
        if ($userId === null || $userId === '') {
            return null;
        }
        $user = User::query()->whereKey($userId)->where('status', 'active')->first();
        if (! $user || ! $user->can('supplier_orders.edit')) {
            throw ValidationException::withMessages(['merchandiser_id' => __('The merchandiser must be an active user who may edit supplier orders.')]);
        }

        return $user;
    }

    private function supplier(int $supplierId): Party
    {
        $supplier = $supplierId > 0 ? Party::query()->whereKey($supplierId)->where('is_supplier', true)->first() : null;
        if (! $supplier) {
            throw ValidationException::withMessages(['groups' => __('Pick a supplier for every group of lines.')]);
        }
        if (! $supplier->active) {
            throw ValidationException::withMessages(['groups' => __(':name is deactivated.', ['name' => $supplier->name])]);
        }

        return $supplier;
    }

    /**
     * The chosen lines with their unit price (the PI line's supplier price when none is given).
     *
     * @param  array<int|string, string|null>  $lines
     * @param  Collection<int, PiLine>  $available  keyed by id
     * @return Collection<int, array{line: PiLine, price: string}>
     */
    private function priceLines(array $lines, Collection $available): Collection
    {
        $priced = collect();
        foreach ($lines as $lineId => $price) {
            $line = $available->get((int) $lineId);
            if (! $line) {
                throw ValidationException::withMessages(['lines' => __('A line you picked is already on a supplier order or is not on this PI. Reload the page.')]);
            }
            $price = $this->blankToNull($price) ?? $line->supplier_price;
            if ($price === null || ! is_numeric($price) || bccomp((string) $price, '0', 4) < 0) {
                throw ValidationException::withMessages(['lines' => __("Enter the supplier's price for line :no.", ['no' => $line->line_no])]);
            }
            $priced->push(['line' => $line, 'price' => (string) $price]);
        }

        return $priced;
    }

    /**
     * @param  Collection<int, array{line: PiLine, price: string}>  $lines
     * @return list<array<string, mixed>> what was added, for the audit
     */
    private function createLines(SupplierOrder $order, Collection $lines): array
    {
        $added = [];
        foreach ($lines as ['line' => $line, 'price' => $price]) {
            SupplierOrderLine::create([
                'supplier_order_id' => $order->id,
                'pi_line_id' => $line->id,
                'cartons' => $line->cartons,
                'qty_per_carton' => $line->qty_per_carton,
                'unit_price' => $price,
            ]);
            $added[] = ['pi_line_id' => $line->id, 'cartons' => $line->cartons, 'unit_price' => $price];
        }

        return $added;
    }

    private function notifyDateMoved(SupplierOrder $order, SupplierOrderDateChange $change, User $by): void
    {
        $managerIds = DB::table('supplier_order_lines as sol')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as c', 'c.id', '=', 'pi.client_id')
            ->where('sol.supplier_order_id', $order->id)
            ->whereNotNull('c.account_manager_id')
            ->distinct()->pluck('c.account_manager_id');

        // Only people who may see the order, never the person who moved the date.
        $recipients = User::query()
            ->whereIn('id', $managerIds->push($order->merchandiser_id)->filter()->unique())
            ->where('id', '!=', $by->id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->can('supplier_orders.view'));

        if ($recipients->isEmpty()) {
            return;
        }

        app(NotificationService::class)->send(
            $recipients,
            __('Delivery date moved: :number', ['number' => $order->number]),
            __(':name moved the delivery date of :number from :old to :new. Reason: :reason', [
                'name' => $by->name,
                'number' => $order->number,
                'old' => $change->old_date?->toDateString() ?? '—',
                'new' => $change->new_date?->toDateString() ?? '—',
                'reason' => $change->reason,
            ]),
            ['type' => 'warning', 'category' => 'supplier_order.delivery_moved', 'icon' => 'calendar-x', 'url' => route('supplier-orders.show', $order)],
        );
    }

    private function blankToNull(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return $value === '' ? null : $value;
    }
}
