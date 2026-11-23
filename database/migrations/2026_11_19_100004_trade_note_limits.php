<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B04: a note is issued only within what it settles — credit notes on a PI number up to the
 * current revision's total, debit notes on a purchase order up to its total, and notes settling a
 * claim up to its latest agreed amount (the claimed amount before one is agreed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION trade_notes_limit_guard() RETURNS trigger AS $$
            DECLARE
                pi_no text;
                cap numeric;
                used numeric;
                c claims%ROWTYPE;
            BEGIN
                IF NOT (OLD.status = 'draft' AND NEW.status = 'issued') THEN
                    RETURN NEW;
                END IF;
                IF NEW.kind = 'credit' AND NEW.proforma_invoice_id IS NOT NULL THEN
                    SELECT pi_number INTO pi_no FROM proforma_invoices WHERE id = NEW.proforma_invoice_id;
                    SELECT COALESCE(SUM(l.amount), 0) INTO cap FROM pi_lines l
                        WHERE l.proforma_invoice_id = COALESCE((SELECT id FROM proforma_invoices WHERE pi_number = pi_no AND is_current), NEW.proforma_invoice_id);
                    SELECT COALESCE(SUM(l.amount), 0) INTO used FROM trade_notes n JOIN trade_note_lines l ON l.trade_note_id = n.id
                        JOIN proforma_invoices r ON r.id = n.proforma_invoice_id
                        WHERE n.kind = 'credit' AND r.pi_number = pi_no AND n.currency = NEW.currency AND (n.status = 'issued' OR n.id = NEW.id);
                    IF used > cap THEN
                        RAISE EXCEPTION 'credit notes on PI % would exceed its total', pi_no USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.kind = 'debit' AND NEW.supplier_order_id IS NOT NULL THEN
                    SELECT COALESCE(SUM(amount), 0) INTO cap FROM supplier_order_lines WHERE supplier_order_id = NEW.supplier_order_id;
                    SELECT COALESCE(SUM(l.amount), 0) INTO used FROM trade_notes n JOIN trade_note_lines l ON l.trade_note_id = n.id
                        WHERE n.kind = 'debit' AND n.supplier_order_id = NEW.supplier_order_id AND n.currency = NEW.currency AND (n.status = 'issued' OR n.id = NEW.id);
                    IF used > cap THEN
                        RAISE EXCEPTION 'debit notes on this purchase order would exceed its total' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.claim_id IS NOT NULL THEN
                    SELECT * INTO c FROM claims WHERE id = NEW.claim_id;
                    cap := COALESCE((SELECT agreed_amount FROM claim_events WHERE claim_id = c.id AND agreed_amount IS NOT NULL ORDER BY id DESC LIMIT 1), c.claimed_amount);
                    IF cap IS NOT NULL AND (c.currency IS NULL OR c.currency = NEW.currency) THEN
                        SELECT COALESCE(SUM(l.amount), 0) INTO used FROM trade_notes n JOIN trade_note_lines l ON l.trade_note_id = n.id
                            WHERE n.claim_id = c.id AND n.currency = NEW.currency AND (n.status = 'issued' OR n.id = NEW.id);
                        IF used > cap THEN
                            RAISE EXCEPTION 'notes settling claim % would exceed its agreed amount', c.number USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trade_notes_limit_guard BEFORE UPDATE ON trade_notes
                FOR EACH ROW EXECUTE FUNCTION trade_notes_limit_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS trade_notes_limit_guard() CASCADE;');
    }
};
