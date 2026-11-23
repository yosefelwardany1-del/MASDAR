<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B18: nothing dated in a closed period is entered, not only posted: payments, notes and service
 * invoices when issued, expense receipts and payroll months. (Journal entries were already held.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION in_closed_period(d date) RETURNS boolean AS $$
                SELECT EXISTS (SELECT 1 FROM accounting_periods WHERE closed_at IS NOT NULL AND d BETWEEN starts_on AND ends_on)
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION refuse_closed_period() RETURNS trigger AS $$
            DECLARE
                d date;
            BEGIN
                -- Each table's date, read only on its own branch (NEW has only that table's columns).
                IF TG_TABLE_NAME = 'payments' THEN
                    d := NEW.paid_on;
                ELSIF TG_TABLE_NAME = 'expense_claim_lines' THEN
                    d := NEW.spent_on;
                ELSIF TG_TABLE_NAME = 'payroll_runs' THEN
                    d := (date_trunc('month', NEW.period) + interval '1 month - 1 day')::date;
                ELSIF NEW.status = 'issued' AND OLD.status = 'draft' THEN
                    d := NEW.issued_on;
                END IF;
                IF d IS NOT NULL AND in_closed_period(d) THEN
                    RAISE EXCEPTION 'the period containing % is closed', d USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payments_open_period BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION refuse_closed_period();
            CREATE TRIGGER expense_claim_lines_open_period BEFORE INSERT OR UPDATE ON expense_claim_lines FOR EACH ROW EXECUTE FUNCTION refuse_closed_period();
            CREATE TRIGGER payroll_runs_open_period BEFORE INSERT ON payroll_runs FOR EACH ROW EXECUTE FUNCTION refuse_closed_period();
            CREATE TRIGGER trade_notes_open_period BEFORE UPDATE ON trade_notes FOR EACH ROW EXECUTE FUNCTION refuse_closed_period();
            CREATE TRIGGER service_invoices_open_period BEFORE UPDATE ON service_invoices FOR EACH ROW EXECUTE FUNCTION refuse_closed_period();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_closed_period() CASCADE; DROP FUNCTION IF EXISTS in_closed_period(date);');
    }
};
