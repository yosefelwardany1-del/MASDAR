<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B32: the financial year's start is a setting (Lists & options), not a note on the balance
 * sheet: the month the year starts in, on its 1st (January until MASDAR's accountant says).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('financial_year_start_month')->default(1);
        });
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_financial_year_start_month_check CHECK (financial_year_start_month BETWEEN 1 AND 12)');
    }

    public function down(): void
    {
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn('financial_year_start_month'));
    }
};
