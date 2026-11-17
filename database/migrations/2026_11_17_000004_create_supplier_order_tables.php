<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.3–§5.4: one supplier order per factory (a row of S2), its lines (each a PI line),
 * the history of its promised delivery date, its step events and the goods received.
 * The logs are append-only (I2); received cartons per line never go below zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('parties');
            $table->string('status', 20)->default('open');
            $table->date('confirmation_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->char('currency', 3);
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->foreignId('merchandiser_id')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'delivery_date']);
        });
        DB::statement("ALTER TABLE supplier_orders ADD CONSTRAINT supplier_orders_status_check CHECK (status IN ('open', 'completed', 'cancelled'))");
        DB::statement('ALTER TABLE supplier_orders ADD CONSTRAINT supplier_orders_deposit_check CHECK (deposit_percent IS NULL OR deposit_percent BETWEEN 0 AND 100)');

        Schema::create('supplier_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained('supplier_orders');
            $table->foreignId('pi_line_id')->unique()->constrained('pi_lines');
            $table->decimal('cartons', 18, 4);
            $table->decimal('qty_per_carton', 18, 4);
            $table->decimal('total_qty', 18, 4)->storedAs('round(cartons * qty_per_carton, 4)');
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4)->storedAs('round(cartons * qty_per_carton * unit_price, 4)');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE supplier_order_lines ADD CONSTRAINT supplier_order_lines_quantities_check CHECK (cartons > 0 AND qty_per_carton > 0 AND unit_price >= 0)');

        // A change of the promised date is a new row, never an overwrite: slippage is measured.
        Schema::create('supplier_order_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained('supplier_orders');
            $table->date('old_date')->nullable();
            $table->date('new_date')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        // The latest event per (order, step) is the step's state: done, skipped, or reopened.
        Schema::create('supplier_order_step_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained('supplier_orders');
            $table->foreignId('step_definition_id')->constrained('order_step_definitions');
            $table->string('status', 20);
            $table->date('happened_on');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['supplier_order_id', 'step_definition_id', 'id']);
        });
        DB::statement("ALTER TABLE supplier_order_step_events ADD CONSTRAINT supplier_order_step_events_status_check CHECK (status IN ('done', 'skipped', 'reopened'))");

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_order_line_id')->constrained('supplier_order_lines');
            $table->date('received_on');
            $table->decimal('cartons', 18, 4);
            $table->string('inspection_result', 20);
            $table->decimal('measured_cbm', 18, 4)->nullable();
            $table->decimal('measured_kg', 18, 4)->nullable();
            $table->string('location', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('corrects_receipt_id')->nullable()->constrained('goods_receipts');
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('supplier_order_line_id');
        });
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_cartons_check CHECK (cartons <> 0)');
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_result_check CHECK (inspection_result IN ('pass', 'fail', 'pass_with_notes'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER supplier_order_date_changes_append_only BEFORE UPDATE OR DELETE ON supplier_order_date_changes
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER supplier_order_step_events_append_only BEFORE UPDATE OR DELETE ON supplier_order_step_events
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER goods_receipts_append_only BEFORE UPDATE OR DELETE ON goods_receipts
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            CREATE OR REPLACE FUNCTION goods_receipts_not_negative() RETURNS trigger AS $$
            BEGIN
                IF (SELECT COALESCE(SUM(cartons), 0) FROM goods_receipts WHERE supplier_order_line_id = NEW.supplier_order_line_id) < 0 THEN
                    RAISE EXCEPTION 'received cartons for an order line cannot go below zero' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER goods_receipts_not_negative AFTER INSERT ON goods_receipts
                FOR EACH ROW EXECUTE FUNCTION goods_receipts_not_negative();
        SQL);
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'supplier_order_step_events', 'supplier_order_date_changes', 'supplier_order_lines', 'supplier_orders'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS goods_receipts_not_negative();');
    }
};
