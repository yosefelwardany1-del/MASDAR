<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B21: a statement is imported once. content_hash fingerprints its lines (date, amount,
 * description, reference); one account never holds two statements with the same fingerprint.
 * Statements imported before this have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX bank_statements_once ON bank_statements (money_account_id, content_hash) WHERE content_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_statements_once');
        Schema::table('bank_statements', fn (Blueprint $table) => $table->dropColumn('content_hash'));
    }
};
