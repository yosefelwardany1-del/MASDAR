<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B02: an issued PI is not cancelled while the books rely on it — an unreversed client invoice of
 * any of its revisions, or client receipts on it that are not refunded or reversed. (Its purchase
 * orders are checked in ProformaInvoiceService, which says which to cancel.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proforma_invoices_cancel_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'cancelled' AND OLD.status IN ('issued', 'accepted', 'confirmed') THEN
                    IF EXISTS (SELECT 1 FROM journal_entries e
                               WHERE e.source_type = 'proforma_invoice' AND e.event = 'client_invoice' AND e.reverses_entry_id IS NULL
                                 AND e.source_id IN (SELECT id FROM proforma_invoices WHERE pi_number = OLD.pi_number)
                                 AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_entry_id = e.id)) THEN
                        RAISE EXCEPTION 'PI % is invoiced: reverse its invoice before cancelling it', OLD.pi_number USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM payments p
                               WHERE p.kind = 'client_receipt'
                                 AND p.proforma_invoice_id IN (SELECT id FROM proforma_invoices WHERE pi_number = OLD.pi_number)
                               GROUP BY p.currency HAVING SUM(p.signed_amount) <> 0) THEN
                        RAISE EXCEPTION 'PI % has money received: refund or move it before cancelling it', OLD.pi_number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER proforma_invoices_cancel_guard BEFORE UPDATE OF status ON proforma_invoices
                FOR EACH ROW EXECUTE FUNCTION proforma_invoices_cancel_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS proforma_invoices_cancel_guard() CASCADE;');
    }
};
