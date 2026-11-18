<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.8, factory quotes: a sourcing request (what a client wants, how many, at what target
 * price) gathers quotes from several factories, compared side by side; one is chosen and goes
 * onto a quotation line with its factory and price. Once a request is decided, its quotes no
 * longer change (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('sourcing_prefix', 10)->default('RFQ');
        });

        Schema::create('sourcing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('status', 10)->default('open');
            $table->text('description');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->foreignId('client_id')->nullable()->constrained('parties');
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->decimal('target_price', 18, 4)->nullable();
            $table->char('target_currency', 3)->nullable();
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('chosen_quote_id')->nullable();
            $table->foreignId('quotation_line_id')->nullable()->constrained('quotation_lines');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE sourcing_requests ADD CONSTRAINT sourcing_requests_check CHECK (
            status IN ('open', 'chosen', 'closed')
            AND (quantity IS NULL OR quantity > 0)
            AND (target_price IS NULL OR (target_price >= 0 AND target_currency IS NOT NULL))
            AND ((status = 'chosen') = (chosen_quote_id IS NOT NULL))
            AND (status = 'open' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)))");

        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_request_id')->constrained('sourcing_requests');
            $table->foreignId('supplier_id')->constrained('parties');
            $table->decimal('unit_price', 18, 4);
            $table->char('currency', 3);
            $table->decimal('moq', 18, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('qty_per_carton', 18, 4)->nullable();
            $table->decimal('cbm_per_carton', 18, 4)->nullable();
            $table->decimal('kg_per_carton', 18, 4)->nullable();
            $table->date('received_on');
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('sourcing_request_id');
        });
        DB::statement('ALTER TABLE supplier_quotes ADD CONSTRAINT supplier_quotes_check CHECK (
            unit_price >= 0 AND (moq IS NULL OR moq >= 0) AND (qty_per_carton IS NULL OR qty_per_carton > 0)
            AND (cbm_per_carton IS NULL OR cbm_per_carton >= 0) AND (kg_per_carton IS NULL OR kg_per_carton >= 0)
            AND (valid_until IS NULL OR valid_until >= received_on))');

        Schema::table('sourcing_requests', function (Blueprint $table) {
            $table->foreign('chosen_quote_id')->references('id')->on('supplier_quotes');
        });

        DB::unprepared(<<<'SQL'
            -- A decided request keeps its quotes as they were; the chosen quote is one of its own.
            CREATE OR REPLACE FUNCTION supplier_quotes_guard() RETURNS trigger AS $$
            DECLARE
                r_status text;
            BEGIN
                SELECT status INTO r_status FROM sourcing_requests
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.sourcing_request_id ELSE NEW.sourcing_request_id END;
                IF r_status IS DISTINCT FROM 'open' THEN
                    RAISE EXCEPTION 'the quotes of a decided request never change; reopen it first' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP <> 'DELETE' AND NOT EXISTS (SELECT 1 FROM parties WHERE id = NEW.supplier_id AND is_supplier) THEN
                    RAISE EXCEPTION 'a quote comes from a supplier' USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER supplier_quotes_guard BEFORE INSERT OR UPDATE OR DELETE ON supplier_quotes
                FOR EACH ROW EXECUTE FUNCTION supplier_quotes_guard();

            CREATE OR REPLACE FUNCTION sourcing_requests_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a sourcing request is never deleted; close it' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.number IS DISTINCT FROM OLD.number THEN
                    RAISE EXCEPTION 'a sourcing request keeps its number' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.chosen_quote_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM supplier_quotes WHERE id = NEW.chosen_quote_id AND sourcing_request_id = NEW.id) THEN
                    RAISE EXCEPTION 'the chosen quote is one of the request''s own' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sourcing_requests_guard BEFORE UPDATE OR DELETE ON sourcing_requests
                FOR EACH ROW EXECUTE FUNCTION sourcing_requests_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS supplier_quotes_guard() CASCADE; DROP FUNCTION IF EXISTS sourcing_requests_guard() CASCADE;');
        Schema::table('sourcing_requests', fn (Blueprint $table) => $table->dropForeign(['chosen_quote_id']));
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('sourcing_requests');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn('sourcing_prefix'));
    }
};
