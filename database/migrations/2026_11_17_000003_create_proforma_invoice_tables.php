<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.2. Each revision of a PI is a row; exactly one revision per PI number is current
 * (I4). Line totals are generated columns (I5). An issued PI and its lines never change: the
 * triggers below are the gate, the PHP checks only give the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('pi_number', 40);
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('status', 20)->default('draft');
            $table->foreignId('client_id')->constrained('parties');
            $table->string('shipped_to', 255)->nullable();
            $table->string('po_number', 80)->nullable();
            $table->date('po_date')->nullable();
            $table->char('currency', 3);
            $table->decimal('deposit_percent', 5, 2);
            $table->string('terms_text', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['pi_number', 'revision']);
            $table->index(['client_id', 'status']);
        });
        DB::statement("ALTER TABLE proforma_invoices ADD CONSTRAINT proforma_invoices_status_check CHECK (status IN ('draft', 'issued', 'accepted', 'confirmed', 'cancelled', 'superseded'))");
        DB::statement('ALTER TABLE proforma_invoices ADD CONSTRAINT proforma_invoices_deposit_check CHECK (deposit_percent BETWEEN 0 AND 100)');
        DB::statement('CREATE UNIQUE INDEX proforma_invoices_one_current ON proforma_invoices (pi_number) WHERE is_current');

        Schema::create('pi_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained('proforma_invoices');
            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('products');
            $table->text('description')->nullable();
            $table->string('unit', 60);
            $table->decimal('cartons', 18, 4);
            $table->decimal('qty_per_carton', 18, 4);
            $table->decimal('total_qty', 18, 4)->storedAs('round(cartons * qty_per_carton, 4)');
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4)->storedAs('round(cartons * qty_per_carton * unit_price, 4)');
            $table->decimal('cbm_per_carton', 18, 4)->default(0);
            $table->decimal('kg_per_carton', 18, 4)->default(0);
            $table->decimal('total_cbm', 18, 4)->storedAs('round(cartons * cbm_per_carton, 4)');
            $table->decimal('total_kg', 18, 4)->storedAs('round(cartons * kg_per_carton, 4)');
            $table->foreignId('supplier_id')->nullable()->constrained('parties');
            $table->decimal('supplier_price', 18, 4)->nullable();
            $table->char('supplier_currency', 3)->nullable();
            $table->foreignId('source_line_id')->nullable()->constrained('pi_lines');
            $table->timestamps();
            $table->unique(['proforma_invoice_id', 'line_no']);
        });
        DB::statement('ALTER TABLE pi_lines ADD CONSTRAINT pi_lines_quantities_check CHECK (
            cartons > 0 AND qty_per_carton > 0 AND unit_price >= 0 AND cbm_per_carton >= 0 AND kg_per_carton >= 0
            AND (supplier_price IS NULL OR supplier_price >= 0))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proforma_invoices_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'an issued PI is never deleted; cancel it instead' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'draft' THEN
                    IF NEW.status NOT IN ('draft', 'issued', 'cancelled') THEN
                        RAISE EXCEPTION 'a draft PI can only be issued or cancelled, not %', NEW.status USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF (NEW.pi_number, NEW.revision, NEW.client_id, NEW.shipped_to, NEW.po_number, NEW.po_date,
                    NEW.currency, NEW.deposit_percent, NEW.terms_text, NEW.created_by, NEW.issued_at, NEW.issued_by)
                   IS DISTINCT FROM
                   (OLD.pi_number, OLD.revision, OLD.client_id, OLD.shipped_to, OLD.po_number, OLD.po_date,
                    OLD.currency, OLD.deposit_percent, OLD.terms_text, OLD.created_by, OLD.issued_at, OLD.issued_by) THEN
                    RAISE EXCEPTION 'an issued PI never changes; make a new revision' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status <> OLD.status AND NOT (
                       (OLD.status = 'issued' AND NEW.status IN ('accepted', 'confirmed', 'cancelled', 'superseded'))
                    OR (OLD.status = 'accepted' AND NEW.status IN ('confirmed', 'cancelled', 'superseded'))
                    OR (OLD.status = 'confirmed' AND NEW.status IN ('cancelled', 'superseded'))
                ) THEN
                    RAISE EXCEPTION 'a PI cannot move from % to %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER proforma_invoices_guard BEFORE UPDATE OR DELETE ON proforma_invoices
                FOR EACH ROW EXECUTE FUNCTION proforma_invoices_guard();

            CREATE OR REPLACE FUNCTION pi_lines_guard() RETURNS trigger AS $$
            DECLARE
                pi_status text;
            BEGIN
                SELECT status INTO pi_status FROM proforma_invoices
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.proforma_invoice_id ELSE NEW.proforma_invoice_id END;
                IF pi_status <> 'draft' THEN
                    RAISE EXCEPTION 'the lines of an issued PI never change; make a new revision' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.proforma_invoice_id <> OLD.proforma_invoice_id THEN
                    RAISE EXCEPTION 'a PI line never moves to another PI' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pi_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON pi_lines
                FOR EACH ROW EXECUTE FUNCTION pi_lines_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pi_lines');
        Schema::dropIfExists('proforma_invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS pi_lines_guard(); DROP FUNCTION IF EXISTS proforma_invoices_guard();');
    }
};
