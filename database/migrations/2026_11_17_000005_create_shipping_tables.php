<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.5: a shipment (booking) with its containers, each container's status events (I8:
 * only forward along the container_status list, unless correcting) and what was loaded into
 * it (I6: never more than was received).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('shipping_company_id')->nullable()->constrained('parties');
            $table->string('mode', 60)->nullable();
            $table->string('booking_no', 80)->nullable();
            $table->string('port_of_loading', 60)->nullable();
            $table->string('port_of_discharge', 60)->nullable();
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->string('vessel', 120)->nullable();
            $table->string('voyage', 60)->nullable();
            $table->string('bl_number', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments');
            $table->string('container_no', 20)->nullable();
            $table->string('container_type', 60)->nullable();
            $table->string('seal_no', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('container_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_id')->constrained('containers');
            $table->string('status', 60);
            $table->timestampTz('happened_at');
            $table->string('place', 160)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('corrects_event_id')->nullable()->constrained('container_events');
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['container_id', 'id']);
        });

        Schema::create('container_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('container_id')->constrained('containers');
            $table->foreignId('supplier_order_line_id')->constrained('supplier_order_lines');
            $table->decimal('cartons', 18, 4);
            $table->date('loaded_on');
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('supplier_order_line_id');
            $table->index('container_id');
        });
        DB::statement('ALTER TABLE container_loads ADD CONSTRAINT container_loads_cartons_check CHECK (cartons <> 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER container_events_append_only BEFORE UPDATE OR DELETE ON container_events
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER container_loads_append_only BEFORE UPDATE OR DELETE ON container_loads
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            -- I8: a status is one of the container_status list, and moves only forward along
            -- its order. Going back is a correction that names the event it corrects.
            CREATE OR REPLACE FUNCTION container_events_forward_only() RETURNS trigger AS $$
            DECLARE
                new_sort integer;
                last_sort integer;
            BEGIN
                SELECT sort INTO new_sort FROM lookups WHERE list = 'container_status' AND code = NEW.status;
                IF new_sort IS NULL THEN
                    RAISE EXCEPTION 'unknown container status %', NEW.status USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.corrects_event_id IS NOT NULL THEN
                    IF NOT EXISTS (SELECT 1 FROM container_events WHERE id = NEW.corrects_event_id AND container_id = NEW.container_id) THEN
                        RAISE EXCEPTION 'a correction must name an event of the same container' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                SELECT l.sort INTO last_sort FROM container_events e
                    JOIN lookups l ON l.list = 'container_status' AND l.code = e.status
                    WHERE e.container_id = NEW.container_id ORDER BY e.id DESC LIMIT 1;
                IF last_sort IS NOT NULL AND new_sort < last_sort THEN
                    RAISE EXCEPTION 'a container status only moves forward; record a correction to go back' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER container_events_forward_only BEFORE INSERT ON container_events
                FOR EACH ROW EXECUTE FUNCTION container_events_forward_only();

            -- I6: what is loaded for an order line never exceeds what was received for it.
            CREATE OR REPLACE FUNCTION container_loads_within_received() RETURNS trigger AS $$
            DECLARE
                received numeric;
                loaded numeric;
                in_container numeric;
            BEGIN
                PERFORM 1 FROM supplier_order_lines WHERE id = NEW.supplier_order_line_id FOR UPDATE;
                SELECT COALESCE(SUM(cartons), 0) INTO received FROM goods_receipts WHERE supplier_order_line_id = NEW.supplier_order_line_id;
                SELECT COALESCE(SUM(cartons), 0) INTO loaded FROM container_loads WHERE supplier_order_line_id = NEW.supplier_order_line_id;
                SELECT COALESCE(SUM(cartons), 0) INTO in_container FROM container_loads
                    WHERE supplier_order_line_id = NEW.supplier_order_line_id AND container_id = NEW.container_id;
                IF loaded + NEW.cartons > received THEN
                    RAISE EXCEPTION 'cannot load % cartons: only % received and % already loaded', NEW.cartons, received, loaded
                        USING ERRCODE = 'check_violation';
                END IF;
                IF in_container + NEW.cartons < 0 THEN
                    RAISE EXCEPTION 'cannot unload more than this container holds of the line' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER container_loads_within_received BEFORE INSERT ON container_loads
                FOR EACH ROW EXECUTE FUNCTION container_loads_within_received();
        SQL);
    }

    public function down(): void
    {
        foreach (['container_loads', 'container_events', 'containers', 'shipments'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS container_loads_within_received(); DROP FUNCTION IF EXISTS container_events_forward_only();');
    }
};
