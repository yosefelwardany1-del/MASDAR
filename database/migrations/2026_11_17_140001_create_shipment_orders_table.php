<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.5 and step 9 of §5.3: "this supplier order is booked on this shipment". A row is
 * added when an order is booked and removed when it is unbooked; it never changes. An order
 * cannot be unbooked while any of its cartons are still loaded on the shipment's containers
 * (the trigger below is the gate). Written only by App\Services\Shipping\ShipmentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->foreignId('supplier_order_id')->constrained('supplier_orders');
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['shipment_id', 'supplier_order_id']);
            $table->index('supplier_order_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION shipment_orders_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'a booking never changes; unbook and book again' USING ERRCODE = 'restrict_violation';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM container_loads cl
                    JOIN containers c ON c.id = cl.container_id
                    JOIN supplier_order_lines sol ON sol.id = cl.supplier_order_line_id
                    WHERE c.shipment_id = OLD.shipment_id AND sol.supplier_order_id = OLD.supplier_order_id
                    GROUP BY cl.container_id, cl.supplier_order_line_id
                    HAVING SUM(cl.cartons) <> 0
                ) THEN
                    RAISE EXCEPTION 'cannot unbook an order whose goods are loaded on this shipment; unload them first'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN OLD;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shipment_orders_guard BEFORE UPDATE OR DELETE ON shipment_orders
                FOR EACH ROW EXECUTE FUNCTION shipment_orders_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_orders');
        DB::unprepared('DROP FUNCTION IF EXISTS shipment_orders_guard();');
    }
};
