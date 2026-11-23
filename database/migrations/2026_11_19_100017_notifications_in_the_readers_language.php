<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B31: a notification keeps its text as a key and parameters (App\Support\Text), put into words
 * in the reader's language when shown. title and message keep an English copy: rows from before
 * this change have only those, and they are shown as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_notifications', function (Blueprint $table) {
            $table->jsonb('texts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_notifications', fn (Blueprint $table) => $table->dropColumn('texts'));
    }
};
