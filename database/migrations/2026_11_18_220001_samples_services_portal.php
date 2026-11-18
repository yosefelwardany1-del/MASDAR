<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.8, batch D: samples followed from the factory to the client's answer; service
 * invoices for what MASDAR charges besides goods (commission, inspection, freight recharged);
 * a shipping line's tracking address per shipping company; and the client portal — a user
 * linked to one client party, off until switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('sample_prefix', 10)->default('SMP');
            $table->string('service_invoice_prefix', 10)->default('INV');
            $table->boolean('client_portal_enabled')->default(false);
        });

        Schema::table('parties', function (Blueprint $table) {
            // e.g. https://www.maersk.com/tracking/{container} — {container}, {bl} and {booking} are filled in.
            $table->string('tracking_url_template', 255)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            // A client's portal account: sees only that client's documents (spec Q-R2).
            $table->foreignId('party_id')->nullable()->constrained('parties');
        });

        // ---- samples
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->text('description');
            $table->foreignId('client_id')->nullable()->constrained('parties');
            $table->foreignId('supplier_id')->nullable()->constrained('parties');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->foreignId('supplier_order_id')->nullable()->constrained('supplier_orders');
            $table->decimal('quantity', 18, 4)->nullable();
            $table->date('requested_on');
            $table->date('needed_by')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE samples ADD CONSTRAINT samples_check CHECK (quantity IS NULL OR quantity > 0)');

        Schema::create('sample_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->constrained('samples');
            $table->string('status', 20);
            $table->date('happened_on');
            $table->string('courier', 80)->nullable();
            $table->string('tracking_no', 80)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['sample_id', 'id']);
        });
        DB::statement("ALTER TABLE sample_events ADD CONSTRAINT sample_events_check CHECK (
            status IN ('requested', 'at_factory', 'received', 'sent', 'approved', 'rejected', 'cancelled'))");

        // ---- service invoices
        Schema::create('service_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('status', 10)->default('draft');
            $table->foreignId('client_id')->constrained('parties');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->char('currency', 3);
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });
        DB::statement("ALTER TABLE service_invoices ADD CONSTRAINT service_invoices_check CHECK (
            status IN ('draft', 'issued', 'void')
            AND (status = 'draft' OR (issued_on IS NOT NULL AND issued_at IS NOT NULL AND issued_by IS NOT NULL))
            AND (status <> 'void' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))
            AND (due_on IS NULL OR issued_on IS NULL OR due_on >= issued_on))");

        Schema::create('service_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('service_invoices');
            $table->unsignedInteger('line_no');
            $table->string('category', 60);
            $table->text('description');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4)->storedAs('round(quantity * unit_price, 4)');
            $table->timestamps();
            $table->unique(['service_invoice_id', 'line_no']);
        });
        DB::statement('ALTER TABLE service_invoice_lines ADD CONSTRAINT service_invoice_lines_check CHECK (quantity > 0 AND unit_price > 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER sample_events_append_only BEFORE UPDATE OR DELETE ON sample_events
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            CREATE OR REPLACE FUNCTION service_invoices_guard() RETURNS trigger AS $$
            DECLARE
                total numeric;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'an invoice is created as a draft and then issued' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'only a draft invoice is deleted; void an issued one' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'void' THEN
                    RAISE EXCEPTION 'a void invoice never changes' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'issued' THEN
                    IF NEW.status <> 'void' OR (NEW.number, NEW.client_id, NEW.proforma_invoice_id, NEW.currency, NEW.issued_on, NEW.due_on, NEW.notes, NEW.issued_by, NEW.issued_at)
                       IS DISTINCT FROM (OLD.number, OLD.client_id, OLD.proforma_invoice_id, OLD.currency, OLD.issued_on, OLD.due_on, OLD.notes, OLD.issued_by, OLD.issued_at) THEN
                        RAISE EXCEPTION 'an issued invoice never changes; void it and issue another' USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM journal_entries e WHERE e.source_type = 'service_invoice' AND e.source_id = OLD.id
                               AND e.reverses_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_entry_id = e.id)) THEN
                        RAISE EXCEPTION 'invoice % is posted: reverse its journal entry before voiding it', OLD.number USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.status = 'void' THEN
                    RAISE EXCEPTION 'a draft invoice is deleted, not voided' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'issued' THEN
                    IF NOT EXISTS (SELECT 1 FROM parties WHERE id = NEW.client_id AND is_client) THEN
                        RAISE EXCEPTION 'a service invoice is to a client' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT COALESCE(SUM(amount), 0) INTO total FROM service_invoice_lines WHERE service_invoice_id = NEW.id;
                    IF total <= 0 THEN
                        RAISE EXCEPTION 'invoice % has no amount to issue', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER service_invoices_guard BEFORE INSERT OR UPDATE OR DELETE ON service_invoices FOR EACH ROW EXECUTE FUNCTION service_invoices_guard();

            CREATE OR REPLACE FUNCTION service_invoice_lines_guard() RETURNS trigger AS $$
            DECLARE
                i_status text;
            BEGIN
                SELECT status INTO i_status FROM service_invoices WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.service_invoice_id ELSE NEW.service_invoice_id END;
                IF i_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'the lines of an issued invoice never change' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER service_invoice_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON service_invoice_lines FOR EACH ROW EXECUTE FUNCTION service_invoice_lines_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS service_invoice_lines_guard() CASCADE; DROP FUNCTION IF EXISTS service_invoices_guard() CASCADE;');
        foreach (['service_invoice_lines', 'service_invoices', 'sample_events', 'samples'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('party_id'));
        Schema::table('parties', fn (Blueprint $table) => $table->dropColumn('tracking_url_template'));
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn(['sample_prefix', 'service_invoice_prefix', 'client_portal_enabled']));
    }
};
