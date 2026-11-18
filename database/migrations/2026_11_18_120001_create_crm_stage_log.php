<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.1 (CRM): the stages a person sets for a client are logged, append-only, so the
 * pipeline knows when each was set and the stages after "PI sent", which follow the documents,
 * can be weighed against them. The client_stage list marks those document stages
 * (meta from_documents) and the stages nobody chases (meta closed). A setting says when a
 * client has gone quiet.
 */
return new class extends Migration
{
    private const FROM_DOCUMENTS = ['pi_sent', 'confirmed', 'in_production', 'shipped', 'delivered'];

    public function up(): void
    {
        Schema::create('client_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('from_stage', 60)->nullable();
            $table->string('to_stage', 60)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['party_id', 'id']);
        });
        DB::unprepared('CREATE TRIGGER client_stage_changes_append_only BEFORE UPDATE OR DELETE ON client_stage_changes FOR EACH ROW EXECUTE FUNCTION masdar_append_only()');

        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('crm_quiet_days')->default(30);
        });
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_crm_check CHECK (crm_quiet_days BETWEEN 1 AND 365)');

        // The clients that already have a stage start their log with it, as of when they were added.
        DB::statement(<<<'SQL'
            INSERT INTO client_stage_changes (party_id, from_stage, to_stage, user_id, note, created_at)
            SELECT p.id, NULL, p.stage, p.created_by, 'Stage before the log began', p.created_at
            FROM parties p WHERE p.is_client AND p.stage IS NOT NULL AND p.created_by IS NOT NULL
        SQL);

        // Mark the stages: an existing installation keeps its names and order, and gains the flags.
        foreach (self::FROM_DOCUMENTS as $code) {
            DB::table('lookups')->where('list', 'client_stage')->where('code', $code)
                ->update(['meta' => DB::raw("COALESCE(meta, '{}'::jsonb) || '{\"from_documents\": 1}'::jsonb")]);
        }
        DB::table('lookups')->where('list', 'client_stage')->where('code', 'dormant')
            ->update(['meta' => DB::raw("COALESCE(meta, '{}'::jsonb) || '{\"closed\": 1}'::jsonb")]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT IF EXISTS business_settings_crm_check');
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('crm_quiet_days');
        });
        Schema::dropIfExists('client_stage_changes');
    }
};
