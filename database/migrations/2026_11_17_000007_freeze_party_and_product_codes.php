<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A party's or product's code is on every document that names it, so it never changes once
 * given — held here, whoever writes (CLAUDE.md: invariants live in PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION masdar_code_is_frozen() RETURNS trigger AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'the code of a % row never changes', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER parties_code_frozen BEFORE UPDATE ON parties FOR EACH ROW EXECUTE FUNCTION masdar_code_is_frozen();
            CREATE TRIGGER products_code_frozen BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION masdar_code_is_frozen();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS parties_code_frozen ON parties; DROP TRIGGER IF EXISTS products_code_frozen ON products; DROP FUNCTION IF EXISTS masdar_code_is_frozen();');
    }
};
