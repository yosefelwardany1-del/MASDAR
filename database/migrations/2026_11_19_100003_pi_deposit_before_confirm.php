<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B03: a PI is confirmed once its deposit is paid — client receipts on any revision, in the PI's
 * currency, at least total × deposit % (to the cent) — or a manager confirms it without, giving the
 * reason, which is kept on the PI and never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_invoices', function (Blueprint $table) {
            $table->text('deposit_override_reason')->nullable();
            $table->foreignId('deposit_override_by')->nullable()->constrained('users');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proforma_invoices_confirm_guard() RETURNS trigger AS $$
            DECLARE
                required numeric;
                paid numeric;
            BEGIN
                IF (NEW.deposit_override_reason, NEW.deposit_override_by) IS DISTINCT FROM (OLD.deposit_override_reason, OLD.deposit_override_by)
                   AND NOT (NEW.status = 'confirmed' AND OLD.status <> 'confirmed') THEN
                    RAISE EXCEPTION 'a deposit override is given when the PI is confirmed, and never changes' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status = 'confirmed' AND OLD.status <> 'confirmed' THEN
                    SELECT round(COALESCE(SUM(amount), 0) * NEW.deposit_percent / 100, 2) INTO required
                        FROM pi_lines WHERE proforma_invoice_id = NEW.id;
                    SELECT COALESCE(SUM(p.signed_amount), 0) INTO paid
                        FROM payments p JOIN proforma_invoices r ON r.id = p.proforma_invoice_id
                        WHERE r.pi_number = NEW.pi_number AND p.kind = 'client_receipt' AND p.currency = NEW.currency;
                    IF paid < required AND (NEW.deposit_override_reason IS NULL OR btrim(NEW.deposit_override_reason) = '' OR NEW.deposit_override_by IS NULL) THEN
                        RAISE EXCEPTION 'PI % needs its deposit (% %) before it is confirmed; % is paid', NEW.pi_number, required, NEW.currency, paid
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER proforma_invoices_confirm_guard BEFORE UPDATE ON proforma_invoices
                FOR EACH ROW EXECUTE FUNCTION proforma_invoices_confirm_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS proforma_invoices_confirm_guard() CASCADE;');
        Schema::table('proforma_invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('deposit_override_by'));
        Schema::table('proforma_invoices', fn (Blueprint $table) => $table->dropColumn('deposit_override_reason'));
    }
};
