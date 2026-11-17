<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 3's guards on its own tables, so the database stays the gate (CLAUDE.md):
 *
 *  - A goods receipt is either a receipt (cartons > 0) or a correction (cartons < 0) that names
 *    a receipt of the same line; a correction never removes more than that receipt still holds,
 *    and never takes the line's received cartons below what is already loaded (I6 — the loads
 *    trigger only checks new loads, so a receipt correction could otherwise break it).
 *  - A PI line is on at most one supplier order across its revisions: a line copied into a new
 *    PI revision (source_line_id) is not ordered again when its original already is.
 *  - A step event's payment belongs to the same supplier order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_correction_check CHECK (
            (cartons > 0 AND corrects_receipt_id IS NULL) OR (cartons < 0 AND corrects_receipt_id IS NOT NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION goods_receipts_correction_guard() RETURNS trigger AS $$
            DECLARE
                original goods_receipts%ROWTYPE;
                still_held numeric;
                received numeric;
                loaded numeric;
            BEGIN
                -- The same lock the container loads take: receipts and loads of a line queue up.
                PERFORM 1 FROM supplier_order_lines WHERE id = NEW.supplier_order_line_id FOR UPDATE;

                IF NEW.corrects_receipt_id IS NOT NULL THEN
                    SELECT * INTO original FROM goods_receipts WHERE id = NEW.corrects_receipt_id;
                    IF original.supplier_order_line_id IS DISTINCT FROM NEW.supplier_order_line_id OR original.cartons <= 0 THEN
                        RAISE EXCEPTION 'a correction must name a receipt (not a correction) of the same order line'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT original.cartons + COALESCE(SUM(cartons), 0) INTO still_held
                        FROM goods_receipts WHERE corrects_receipt_id = NEW.corrects_receipt_id;
                    IF still_held + NEW.cartons < 0 THEN
                        RAISE EXCEPTION 'a correction cannot remove more than the % cartons its receipt still holds', still_held
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                IF NEW.cartons < 0 THEN
                    SELECT COALESCE(SUM(cartons), 0) INTO received FROM goods_receipts WHERE supplier_order_line_id = NEW.supplier_order_line_id;
                    SELECT COALESCE(SUM(cartons), 0) INTO loaded FROM container_loads WHERE supplier_order_line_id = NEW.supplier_order_line_id;
                    IF received + NEW.cartons < loaded THEN
                        RAISE EXCEPTION 'received cartons cannot go below the % already loaded', loaded
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER goods_receipts_correction_guard BEFORE INSERT ON goods_receipts
                FOR EACH ROW EXECUTE FUNCTION goods_receipts_correction_guard();

            CREATE OR REPLACE FUNCTION supplier_order_lines_one_order_per_pi_line() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    WITH RECURSIVE ancestors AS (
                        SELECT p.source_line_id AS id FROM pi_lines p WHERE p.id = NEW.pi_line_id
                        UNION
                        SELECT p.source_line_id FROM pi_lines p JOIN ancestors a ON p.id = a.id
                    ), descendants AS (
                        SELECT p.id FROM pi_lines p WHERE p.source_line_id = NEW.pi_line_id
                        UNION
                        SELECT p.id FROM pi_lines p JOIN descendants d ON p.source_line_id = d.id
                    )
                    SELECT 1 FROM supplier_order_lines sol
                    WHERE sol.id IS DISTINCT FROM NEW.id
                      AND (sol.pi_line_id IN (SELECT id FROM ancestors WHERE id IS NOT NULL)
                           OR sol.pi_line_id IN (SELECT id FROM descendants))
                ) THEN
                    RAISE EXCEPTION 'this PI line (or another revision of it) is already on a supplier order'
                        USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_order_lines_one_order_per_pi_line BEFORE INSERT OR UPDATE OF pi_line_id ON supplier_order_lines
                FOR EACH ROW EXECUTE FUNCTION supplier_order_lines_one_order_per_pi_line();

            CREATE OR REPLACE FUNCTION supplier_order_step_events_payment_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.payment_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM payments WHERE id = NEW.payment_id AND supplier_order_id = NEW.supplier_order_id
                ) THEN
                    RAISE EXCEPTION 'a step can only be linked to a payment of the same supplier order'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_order_step_events_payment_guard BEFORE INSERT ON supplier_order_step_events
                FOR EACH ROW EXECUTE FUNCTION supplier_order_step_events_payment_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_order_step_events_payment_guard ON supplier_order_step_events;
            DROP FUNCTION IF EXISTS supplier_order_step_events_payment_guard();
            DROP TRIGGER IF EXISTS supplier_order_lines_one_order_per_pi_line ON supplier_order_lines;
            DROP FUNCTION IF EXISTS supplier_order_lines_one_order_per_pi_line();
            DROP TRIGGER IF EXISTS goods_receipts_correction_guard ON goods_receipts;
            DROP FUNCTION IF EXISTS goods_receipts_correction_guard();
            ALTER TABLE goods_receipts DROP CONSTRAINT IF EXISTS goods_receipts_correction_check;
        SQL);
    }
};
