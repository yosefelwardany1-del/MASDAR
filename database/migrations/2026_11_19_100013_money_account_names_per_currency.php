<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B23: a money account's name is unique within its currency (case and spacing aside). Checked when
 * an account is created or renamed: two accounts that already share a name are left for MASDAR to
 * rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION money_accounts_name_once() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM money_accounts m WHERE m.id <> NEW.id AND m.currency = NEW.currency
                           AND lower(regexp_replace(btrim(m.name), '\s+', ' ', 'g')) = lower(regexp_replace(btrim(NEW.name), '\s+', ' ', 'g'))) THEN
                    RAISE EXCEPTION 'there is already a % money account named %', NEW.currency, NEW.name USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER money_accounts_name_once BEFORE INSERT OR UPDATE OF name, currency ON money_accounts
                FOR EACH ROW EXECUTE FUNCTION money_accounts_name_once();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS money_accounts_name_once() CASCADE;');
    }
};
