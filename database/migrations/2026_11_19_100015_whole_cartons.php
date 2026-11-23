<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B27: PI and quotation lines are in whole cartons, unless the business sells part cartons of
 * some goods and switches that on (Lists & options).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->boolean('part_cartons_allowed')->default(false);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lines_whole_cartons() RETURNS trigger AS $$
            BEGIN
                IF NEW.cartons IS NOT NULL AND NEW.cartons <> trunc(NEW.cartons)
                   AND NOT COALESCE((SELECT part_cartons_allowed FROM business_settings WHERE id = 1), FALSE) THEN
                    RAISE EXCEPTION 'cartons are whole: % is not', NEW.cartons USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER pi_lines_whole_cartons BEFORE INSERT OR UPDATE OF cartons ON pi_lines FOR EACH ROW EXECUTE FUNCTION lines_whole_cartons();
            CREATE TRIGGER quotation_lines_whole_cartons BEFORE INSERT OR UPDATE OF cartons ON quotation_lines FOR EACH ROW EXECUTE FUNCTION lines_whole_cartons();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS lines_whole_cartons() CASCADE;');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn('part_cartons_allowed'));
    }
};
