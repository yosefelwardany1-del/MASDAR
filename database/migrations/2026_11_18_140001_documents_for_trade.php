<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trading documents around the PI (spec §5.2, §5.3, §5.5): purchase orders are numbered PO
 * (a supplier order is MASDAR's purchase order; "SO" read as a sales order), the purchase
 * contract printed for the factory takes MASDAR's terms, the commercial invoice its incoterm
 * and bank details, and quotations come before the PI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PO from now on — but never renumber: only while no order has been numbered yet.
        DB::statement("ALTER TABLE business_settings ALTER COLUMN supplier_order_prefix SET DEFAULT 'PO'");
        if (! DB::table('supplier_orders')->exists()) {
            DB::table('business_settings')->where('supplier_order_prefix', 'SO')->update(['supplier_order_prefix' => 'PO']);
        }

        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('quotation_prefix', 10)->default('QT');
            $table->unsignedSmallInteger('quotation_valid_days')->default(30);
            $table->text('purchase_contract_terms')->nullable();
            $table->string('default_incoterm', 60)->default('FOB');
            $table->text('invoice_bank_details')->nullable();
        });
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_quotation_check CHECK (quotation_valid_days BETWEEN 1 AND 365)');

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('incoterm', 60)->nullable();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('client_id')->constrained('parties');
            $table->string('status', 12)->default('draft');
            $table->date('quoted_on');
            $table->date('valid_until');
            $table->char('currency', 3);
            $table->string('incoterm', 60)->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_check CHECK (
            status IN ('draft', 'sent', 'accepted', 'declined') AND valid_until >= quoted_on
            AND (status = 'draft' OR (sent_at IS NOT NULL AND sent_by IS NOT NULL))
            AND (proforma_invoice_id IS NULL OR status = 'accepted'))");

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations');
            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->text('description');
            $table->string('unit', 30);
            $table->decimal('qty_per_carton', 18, 4)->default(0);
            // A proposed quantity, when the client asked for one; the PI takes it.
            $table->decimal('cartons', 18, 4)->nullable();
            $table->decimal('moq', 18, 4)->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('cbm_per_carton', 18, 4)->default(0);
            $table->decimal('kg_per_carton', 18, 4)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained('parties');
            $table->decimal('supplier_price', 18, 4)->nullable();
            $table->char('supplier_currency', 3)->nullable();
            $table->timestamps();
            $table->unique(['quotation_id', 'line_no']);
        });
        DB::statement('ALTER TABLE quotation_lines ADD CONSTRAINT quotation_lines_check CHECK (
            unit_price >= 0 AND qty_per_carton >= 0 AND cbm_per_carton >= 0 AND kg_per_carton >= 0 AND (cartons IS NULL OR cartons > 0)
            AND (moq IS NULL OR moq >= 0) AND (supplier_price IS NULL OR supplier_price >= 0))');

        DB::unprepared(<<<'SQL'
            -- A quotation sent to the client never changes, but for the client's answer and the PI it became.
            CREATE OR REPLACE FUNCTION quotations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'only a draft quotation is deleted' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;
                IF (NEW.number, NEW.client_id, NEW.quoted_on, NEW.valid_until, NEW.currency, NEW.incoterm, NEW.terms, NEW.sent_by, NEW.sent_at)
                   IS DISTINCT FROM (OLD.number, OLD.client_id, OLD.quoted_on, OLD.valid_until, OLD.currency, OLD.incoterm, OLD.terms, OLD.sent_by, OLD.sent_at) THEN
                    RAISE EXCEPTION 'a sent quotation never changes; copy it to revise it' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status IN ('accepted', 'declined') AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'the client''s answer to a quotation is final' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'sent' AND NEW.status NOT IN ('sent', 'accepted', 'declined') THEN
                    RAISE EXCEPTION 'a sent quotation is accepted or declined' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.proforma_invoice_id IS NOT NULL AND NEW.proforma_invoice_id IS DISTINCT FROM OLD.proforma_invoice_id THEN
                    RAISE EXCEPTION 'a quotation becomes one PI' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER quotations_guard BEFORE UPDATE OR DELETE ON quotations FOR EACH ROW EXECUTE FUNCTION quotations_guard();

            CREATE OR REPLACE FUNCTION quotation_lines_guard() RETURNS trigger AS $$
            DECLARE
                q_status text;
            BEGIN
                SELECT status INTO q_status FROM quotations WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.quotation_id ELSE NEW.quotation_id END;
                IF q_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'the lines of a sent quotation never change' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER quotation_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_lines FOR EACH ROW EXECUTE FUNCTION quotation_lines_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS quotations_guard() CASCADE; DROP FUNCTION IF EXISTS quotation_lines_guard() CASCADE;');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('incoterm'));
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT IF EXISTS business_settings_quotation_check');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn(['quotation_prefix', 'quotation_valid_days', 'purchase_contract_terms', 'default_incoterm', 'invoice_bank_details']));
        DB::statement("ALTER TABLE business_settings ALTER COLUMN supplier_order_prefix SET DEFAULT 'SO'");
    }
};
