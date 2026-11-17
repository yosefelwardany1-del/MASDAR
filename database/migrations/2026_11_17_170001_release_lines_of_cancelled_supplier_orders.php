<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cancelled factory order gives its PI lines back, so they can be ordered from another
 * factory. A line is "released" (released_at) when its order is cancelled — only possible while
 * nothing was received for it — and the one-order-per-PI-line rule then counts live lines only.
 * Reopening the order takes them back, unless one was ordered elsewhere meanwhile (the unique
 * index refuses it). The history of the cancelled order stays: lines are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_order_lines', function (Blueprint $table) {
            $table->timestampTz('released_at')->nullable();
        });

        DB::statement('ALTER TABLE supplier_order_lines DROP CONSTRAINT supplier_order_lines_pi_line_id_unique');
        DB::statement('CREATE UNIQUE INDEX supplier_order_lines_one_live_line ON supplier_order_lines (pi_line_id) WHERE released_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION supplier_order_lines_one_order_per_pi_line() RETURNS trigger AS $$
            BEGIN
                IF NEW.released_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;
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
                      AND sol.released_at IS NULL
                      AND (sol.pi_line_id = NEW.pi_line_id
                           OR sol.pi_line_id IN (SELECT id FROM ancestors WHERE id IS NOT NULL)
                           OR sol.pi_line_id IN (SELECT id FROM descendants))
                ) THEN
                    RAISE EXCEPTION 'this PI line (or another revision of it) is already on a supplier order'
                        USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS supplier_order_lines_one_order_per_pi_line ON supplier_order_lines;
            CREATE TRIGGER supplier_order_lines_one_order_per_pi_line BEFORE INSERT OR UPDATE OF pi_line_id, released_at ON supplier_order_lines
                FOR EACH ROW EXECUTE FUNCTION supplier_order_lines_one_order_per_pi_line();

            -- A line is released only when its order is cancelled and nothing was received for it.
            CREATE OR REPLACE FUNCTION supplier_order_lines_release_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.released_at IS NULL AND NEW.released_at IS NOT NULL THEN
                    IF (SELECT status FROM supplier_orders WHERE id = NEW.supplier_order_id) <> 'cancelled' THEN
                        RAISE EXCEPTION 'only the lines of a cancelled supplier order are released' USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM goods_receipts WHERE supplier_order_line_id = NEW.id) THEN
                        RAISE EXCEPTION 'a line with goods received is never released' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_order_lines_release_guard BEFORE UPDATE OF released_at ON supplier_order_lines
                FOR EACH ROW EXECUTE FUNCTION supplier_order_lines_release_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_order_lines_release_guard ON supplier_order_lines; DROP FUNCTION IF EXISTS supplier_order_lines_release_guard();');
        DB::statement('DROP INDEX IF EXISTS supplier_order_lines_one_live_line');
        DB::statement('ALTER TABLE supplier_order_lines ADD CONSTRAINT supplier_order_lines_pi_line_id_unique UNIQUE (pi_line_id)');
        Schema::table('supplier_order_lines', fn (Blueprint $table) => $table->dropColumn('released_at'));
    }
};
