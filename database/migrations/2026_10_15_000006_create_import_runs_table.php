<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per import: the history of synchronous imports and the progress row of a
 * queued one (queued → running → completed | failed). Written only by ImportRun's own
 * methods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource');
            $table->boolean('is_preview')->default(false);
            $table->string('filename')->nullable();
            $table->string('upload_path')->nullable();
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->string('status')->default('completed');
            $table->jsonb('errors')->nullable();
            $table->jsonb('notices')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_runs_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_runs_counts_check CHECK (total_rows >= 0 AND processed_rows >= 0 AND created_count >= 0 AND updated_count >= 0 AND failed_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
