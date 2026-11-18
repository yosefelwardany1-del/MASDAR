<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.8 (beyond phase 1), batch A: quality claims with factories and clients, the credit
 * notes MASDAR gives a client and the debit notes it raises on a factory (both posted to the
 * books), a log of every document sent by email or WhatsApp, and the settings the profit and
 * ageing reports read (how a shared container's costs split, Q-C3; the ageing buckets).
 *
 * A claim's status is its latest event (append-only). An issued note never changes; it is
 * voided only once nothing it posted stands. A send is logged whether it went or failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('container_cost_split', 10)->default('cbm');
            $table->string('ageing_buckets', 40)->default('30,60,90');
            $table->string('claim_prefix', 10)->default('CL');
            $table->string('credit_note_prefix', 10)->default('CN');
            $table->string('debit_note_prefix', 10)->default('DN');
        });
        DB::statement("ALTER TABLE business_settings ADD CONSTRAINT business_settings_reports_check CHECK (
            container_cost_split IN ('cbm', 'kg', 'value', 'cartons')
            AND ageing_buckets ~ '^[0-9]{1,3}(,[0-9]{1,3}){0,5}$')");

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('kind', 60);
            $table->foreignId('supplier_id')->nullable()->constrained('parties');
            $table->foreignId('supplier_order_id')->nullable()->constrained('supplier_orders');
            $table->foreignId('client_id')->nullable()->constrained('parties');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->date('opened_on');
            $table->text('description');
            $table->decimal('cartons', 18, 4)->nullable();
            $table->decimal('claimed_amount', 18, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('supplier_order_id');
            $table->index('proforma_invoice_id');
        });
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_check CHECK (
            (supplier_id IS NOT NULL OR client_id IS NOT NULL)
            AND (cartons IS NULL OR cartons > 0)
            AND (claimed_amount IS NULL OR (claimed_amount > 0 AND currency IS NOT NULL)))');

        Schema::create('claim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims');
            $table->string('status', 20);
            $table->decimal('agreed_amount', 18, 4)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['claim_id', 'id']);
        });
        DB::statement("ALTER TABLE claim_events ADD CONSTRAINT claim_events_check CHECK (
            status IN ('open', 'with_factory', 'agreed', 'rejected', 'closed')
            AND (agreed_amount IS NULL OR agreed_amount >= 0))");

        Schema::create('trade_notes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 6);
            $table->string('number', 40)->unique();
            $table->string('status', 10)->default('draft');
            $table->foreignId('party_id')->constrained('parties');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->foreignId('supplier_order_id')->nullable()->constrained('supplier_orders');
            $table->foreignId('claim_id')->nullable()->constrained('claims');
            $table->char('currency', 3);
            $table->date('issued_on')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
            $table->index('proforma_invoice_id');
            $table->index('supplier_order_id');
        });
        DB::statement("ALTER TABLE trade_notes ADD CONSTRAINT trade_notes_check CHECK (
            kind IN ('credit', 'debit') AND status IN ('draft', 'issued', 'void')
            AND (kind = 'credit' OR proforma_invoice_id IS NULL)
            AND (kind = 'debit' OR supplier_order_id IS NULL)
            AND (status = 'draft' OR (issued_on IS NOT NULL AND issued_at IS NOT NULL AND issued_by IS NOT NULL))
            AND (status <> 'void' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))");

        Schema::create('trade_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_note_id')->constrained('trade_notes');
            $table->unsignedInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4)->storedAs('round(quantity * unit_price, 4)');
            $table->timestamps();
            $table->unique(['trade_note_id', 'line_no']);
        });
        DB::statement('ALTER TABLE trade_note_lines ADD CONSTRAINT trade_note_lines_check CHECK (quantity > 0 AND unit_price > 0)');

        Schema::create('document_sends', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('reference', 120);
            $table->string('channel', 10);
            $table->text('recipients');
            $table->string('subject', 255)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['document_type', 'document_id']);
        });
        DB::statement("ALTER TABLE document_sends ADD CONSTRAINT document_sends_check CHECK (
            channel IN ('email', 'whatsapp') AND status IN ('sent', 'failed') AND (status = 'sent' OR error IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER claim_events_append_only BEFORE UPDATE OR DELETE ON claim_events
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER document_sends_append_only BEFORE UPDATE OR DELETE ON document_sends
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            -- A claim is never deleted; its facts change only while it is still being worked on.
            CREATE OR REPLACE FUNCTION claims_guard() RETURNS trigger AS $$
            DECLARE
                latest text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a claim is never deleted; close or reject it' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.number IS DISTINCT FROM OLD.number THEN
                    RAISE EXCEPTION 'a claim keeps its number' USING ERRCODE = 'restrict_violation';
                END IF;
                SELECT status INTO latest FROM claim_events WHERE claim_id = OLD.id ORDER BY id DESC LIMIT 1;
                IF latest IN ('closed', 'rejected') THEN
                    RAISE EXCEPTION 'a closed or rejected claim never changes; reopen it first' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER claims_guard BEFORE UPDATE OR DELETE ON claims FOR EACH ROW EXECUTE FUNCTION claims_guard();

            -- A credit or debit note: a draft changes freely; issuing checks its party, document and
            -- currency; an issued note never changes, and is voided only once its posting is reversed.
            CREATE OR REPLACE FUNCTION trade_notes_guard() RETURNS trigger AS $$
            DECLARE
                p parties%ROWTYPE;
                doc_party bigint;
                doc_currency text;
                total numeric;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'a note is created as a draft and then issued' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'only a draft note is deleted; void an issued one' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'void' THEN
                    RAISE EXCEPTION 'a void note never changes' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'issued' THEN
                    IF NEW.status <> 'void' OR (NEW.kind, NEW.number, NEW.party_id, NEW.proforma_invoice_id, NEW.supplier_order_id, NEW.claim_id,
                        NEW.currency, NEW.issued_on, NEW.reason, NEW.issued_by, NEW.issued_at)
                       IS DISTINCT FROM (OLD.kind, OLD.number, OLD.party_id, OLD.proforma_invoice_id, OLD.supplier_order_id, OLD.claim_id,
                        OLD.currency, OLD.issued_on, OLD.reason, OLD.issued_by, OLD.issued_at) THEN
                        RAISE EXCEPTION 'an issued note never changes; void it and issue another' USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM journal_entries e WHERE e.source_type = 'trade_note' AND e.source_id = OLD.id
                               AND e.reverses_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_entry_id = e.id)) THEN
                        RAISE EXCEPTION 'note % is posted: reverse its journal entry before voiding it', OLD.number USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                -- From a draft.
                IF NEW.status = 'void' THEN
                    RAISE EXCEPTION 'a draft note is deleted, not voided' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'issued' THEN
                    SELECT * INTO p FROM parties WHERE id = NEW.party_id;
                    IF NEW.kind = 'credit' AND NOT p.is_client THEN
                        RAISE EXCEPTION 'a credit note is given to a client' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.kind = 'debit' AND NOT p.is_supplier THEN
                        RAISE EXCEPTION 'a debit note is raised on a supplier' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.proforma_invoice_id IS NOT NULL THEN
                        SELECT client_id, currency INTO doc_party, doc_currency FROM proforma_invoices WHERE id = NEW.proforma_invoice_id;
                    ELSIF NEW.supplier_order_id IS NOT NULL THEN
                        SELECT supplier_id, currency INTO doc_party, doc_currency FROM supplier_orders WHERE id = NEW.supplier_order_id;
                    END IF;
                    IF doc_party IS NOT NULL AND (doc_party <> NEW.party_id OR doc_currency <> NEW.currency) THEN
                        RAISE EXCEPTION 'a note is in the currency of its document and to its party' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT COALESCE(SUM(amount), 0) INTO total FROM trade_note_lines WHERE trade_note_id = NEW.id;
                    IF total <= 0 THEN
                        RAISE EXCEPTION 'note % has no amount to issue', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trade_notes_guard BEFORE INSERT OR UPDATE OR DELETE ON trade_notes FOR EACH ROW EXECUTE FUNCTION trade_notes_guard();

            CREATE OR REPLACE FUNCTION trade_note_lines_guard() RETURNS trigger AS $$
            DECLARE
                n_status text;
            BEGIN
                SELECT status INTO n_status FROM trade_notes WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.trade_note_id ELSE NEW.trade_note_id END;
                IF n_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'the lines of an issued note never change' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trade_note_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON trade_note_lines FOR EACH ROW EXECUTE FUNCTION trade_note_lines_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS trade_note_lines_guard() CASCADE; DROP FUNCTION IF EXISTS trade_notes_guard() CASCADE; DROP FUNCTION IF EXISTS claims_guard() CASCADE;');
        foreach (['document_sends', 'trade_note_lines', 'trade_notes', 'claim_events', 'claims'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT IF EXISTS business_settings_reports_check');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn([
            'container_cost_split', 'ageing_buckets', 'claim_prefix', 'credit_note_prefix', 'debit_note_prefix',
        ]));
    }
};
