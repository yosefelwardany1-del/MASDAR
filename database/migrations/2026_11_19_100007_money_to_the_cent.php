<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B09: every money amount is to the cent (every listed currency has 2 decimals). Unit prices keep
 * up to 4 decimals; a line's amount is round(quantity × price, 2) and a document's total the sum
 * of its rounded lines. A payment's book amount is round(amount × rate, 2). Journal lines are
 * written to the cent from now on (checked NOT VALID: entries posted before stay as they are —
 * a posted entry never changes).
 *
 * Existing rows: the generated line amounts are recomputed to the cent (a change of less than
 * half a cent per line); posted journal entries are not rewritten.
 */
return new class extends Migration
{
    /** table => [column, expression] */
    private const GENERATED = [
        'pi_lines' => ['amount', 'round(cartons * qty_per_carton * unit_price, 2)'],
        'supplier_order_lines' => ['amount', 'round(cartons * qty_per_carton * unit_price, 2)'],
        'trade_note_lines' => ['amount', 'round(quantity * unit_price, 2)'],
        'service_invoice_lines' => ['amount', 'round(quantity * unit_price, 2)'],
    ];

    public function up(): void
    {
        foreach (self::GENERATED as $table => [$column, $expression]) {
            $this->regenerate($table, $column, $expression);
        }
        $this->regenerate('payments', 'book_amount', 'round(amount * rate, 2)');
        $this->regenerate('payments', 'signed_amount',
            "round(amount * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 2)");
        $this->regenerate('payments', 'signed_book_amount',
            "round(round(amount * rate, 2) * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 2)");

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_cents_check CHECK (amount = round(amount, 2)) NOT VALID');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_cents_check CHECK (
            debit = round(debit, 2) AND credit = round(credit, 2) AND amount_currency = round(amount_currency, 2)) NOT VALID');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_cents_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_cents_check');
        foreach (self::GENERATED as $table => [$column, $expression]) {
            $this->regenerate($table, $column, str_replace(', 2)', ', 4)', $expression));
        }
        $this->regenerate('payments', 'book_amount', 'round(amount * rate, 4)');
        $this->regenerate('payments', 'signed_amount',
            "round(amount * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 4)");
        $this->regenerate('payments', 'signed_book_amount',
            "round(amount * rate * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 4)");
    }

    /** Swap a stored generated column for one with $expression (row triggers do not fire on the rewrite). */
    private function regenerate(string $table, string $column, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
        DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} numeric(18,4) GENERATED ALWAYS AS ({$expression}) STORED");
    }
};
