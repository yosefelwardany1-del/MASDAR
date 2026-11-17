<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec Q-C4: which container status changes a client is told of, over WhatsApp. Off until a
 * manager switches it on (Lists & options) and the template is approved by Meta. Every
 * attempt is logged, append-only, with the number shown only by its last four digits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->boolean('whatsapp_container_updates')->default(false);
            $table->string('whatsapp_update_statuses', 255)->default('on_board,arrived');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 40);
            $table->foreignId('container_id')->nullable()->constrained('containers');
            $table->foreignId('container_event_id')->nullable()->constrained('container_events');
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->string('to_last4', 8)->nullable();
            $table->string('template', 80);
            $table->boolean('sent');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['container_id', 'id']);
        });
        DB::unprepared('CREATE TRIGGER whatsapp_messages_append_only BEFORE UPDATE OR DELETE ON whatsapp_messages FOR EACH ROW EXECUTE FUNCTION masdar_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn(['whatsapp_container_updates', 'whatsapp_update_statuses']));
    }
};
