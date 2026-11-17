<?php

namespace App\Services\SupplierOrders;

use App\Models\GoodsReceipt;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of goods_receipts — step 8 of S2, "Examine & receive goods & update in the
 * system" (spec §5.3–§5.4). Append-only: a mistake is corrected by a negative row naming the
 * receipt it corrects. The database refuses received cartons below zero or below what is
 * already loaded (triggers); that refusal comes back to the person as a validation error.
 * When every line of the order has all its cartons, the receive_goods step is marked done.
 */
class GoodsReceiptService
{
    public const RESULTS = ['pass', 'fail', 'pass_with_notes'];

    public function __construct(private OrderStepService $steps) {}

    /**
     * @param  array{received_on?: string, cartons: string|int, inspection_result: string, measured_cbm?: ?string, measured_kg?: ?string, location?: ?string, note?: ?string}  $data
     */
    public function receive(SupplierOrderLine $line, array $data, User $by): GoodsReceipt
    {
        $cartons = (string) $data['cartons'];
        if (! is_numeric($cartons) || bccomp($cartons, '0', 4) <= 0) {
            throw ValidationException::withMessages(['cartons' => __('Enter the cartons received (more than zero).')]);
        }
        if (! in_array($data['inspection_result'] ?? null, self::RESULTS, true)) {
            throw ValidationException::withMessages(['inspection_result' => __('Pick the inspection result.')]);
        }
        $receivedOn = $this->pastOrToday($data['received_on'] ?? null);

        return DB::transaction(function () use ($line, $data, $by, $cartons, $receivedOn) {
            $order = SupplierOrder::query()->whereKey($line->supplier_order_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'open') {
                throw ValidationException::withMessages(['cartons' => __('This order is not open. Reopen it to receive goods.')]);
            }

            $receipt = GoodsReceipt::create([
                'supplier_order_line_id' => $line->id,
                'received_on' => $receivedOn,
                'cartons' => $cartons,
                'inspection_result' => $data['inspection_result'],
                'measured_cbm' => $this->blankToNull($data['measured_cbm'] ?? null),
                'measured_kg' => $this->blankToNull($data['measured_kg'] ?? null),
                'location' => $this->blankToNull($data['location'] ?? null),
                'note' => $this->blankToNull($data['note'] ?? null),
                'user_id' => $by->id,
            ]);
            AuditTrail::record('created', $receipt, [], $receipt->only([
                'supplier_order_line_id', 'received_on', 'cartons', 'inspection_result', 'measured_cbm', 'measured_kg', 'location', 'note',
            ]) + ['supplier_order' => $order->number]);

            if ($this->isFullyReceived($order)) {
                $this->steps->recordAutomatic($order, 'receive_goods', $by, 'All cartons received', null, $receivedOn);
            }

            return $receipt;
        });
    }

    /**
     * Take $data['cartons'] cartons (a positive number) off $receipt: a negative row naming it.
     *
     * @param  array{cartons: string|int, note: string, received_on?: ?string}  $data
     */
    public function correct(GoodsReceipt $receipt, array $data, User $by): GoodsReceipt
    {
        $cartons = (string) $data['cartons'];
        if (! is_numeric($cartons) || bccomp($cartons, '0', 4) <= 0) {
            throw ValidationException::withMessages(['cartons' => __('Enter the cartons to take off (more than zero).')]);
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            throw ValidationException::withMessages(['note' => __('Give a reason for the correction.')]);
        }
        if ($receipt->corrects_receipt_id !== null) {
            throw ValidationException::withMessages(['cartons' => __('A correction is not corrected itself: correct the original receipt.')]);
        }
        $receivedOn = $this->pastOrToday($data['received_on'] ?? null);

        try {
            return DB::transaction(function () use ($receipt, $by, $cartons, $note, $receivedOn) {
                $correction = GoodsReceipt::create([
                    'supplier_order_line_id' => $receipt->supplier_order_line_id,
                    'received_on' => $receivedOn,
                    'cartons' => bcsub('0', $cartons, 4),
                    'inspection_result' => $receipt->inspection_result,
                    'note' => $note,
                    'corrects_receipt_id' => $receipt->id,
                    'user_id' => $by->id,
                ]);
                AuditTrail::record('corrected', $correction, [], $correction->only([
                    'supplier_order_line_id', 'received_on', 'cartons', 'corrects_receipt_id', 'note',
                ]));

                return $correction;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) !== '23514') {
                throw $e;
            }

            // The database refused: received cartons would go below zero, below what is loaded,
            // or below what this receipt still holds. Say how much may be taken off instead.
            throw ValidationException::withMessages(['cartons' => __('This correction would take the received cartons below zero or below what is already loaded. At most :max cartons can be taken off this receipt.', [
                'max' => $this->correctable($receipt),
            ])]);
        }
    }

    /** How many cartons a correction of $receipt may still take off (never below loaded). */
    public function correctable(GoodsReceipt $receipt): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT r.cartons + COALESCE(SUM(c.cartons), 0) FROM goods_receipts c WHERE c.corrects_receipt_id = r.id) AS held,
                (SELECT COALESCE(SUM(g.cartons), 0) FROM goods_receipts g WHERE g.supplier_order_line_id = r.supplier_order_line_id)
                  - (SELECT COALESCE(SUM(l.cartons), 0) FROM container_loads l WHERE l.supplier_order_line_id = r.supplier_order_line_id) AS unloaded
            FROM goods_receipts r WHERE r.id = ?
        SQL, [$receipt->id]);

        $max = bccomp((string) $row->held, (string) $row->unloaded, 4) < 0 ? (string) $row->held : (string) $row->unloaded;
        $max = bccomp($max, '0', 4) < 0 ? '0' : $max;

        return Quantity::trim(bcadd($max, '0', 4));
    }

    /** Every line of the order has received at least the cartons ordered. */
    public function isFullyReceived(SupplierOrder $order): bool
    {
        return DB::table('supplier_order_lines as sol')
            ->where('sol.supplier_order_id', $order->id)
            ->exists()
            && ! DB::table('supplier_order_lines as sol')
                ->where('sol.supplier_order_id', $order->id)
                ->whereRaw('sol.cartons > COALESCE((SELECT SUM(g.cartons) FROM goods_receipts g WHERE g.supplier_order_line_id = sol.id), 0)')
                ->exists();
    }

    private function pastOrToday(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return CarbonImmutable::today()->toDateString();
        }
        try {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['received_on' => __('Enter a valid date.')]);
        }
        if ($day->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['received_on' => __('Goods cannot be received on a future date.')]);
        }

        return $day->toDateString();
    }

    private function blankToNull(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return $value === '' ? null : $value;
    }
}
