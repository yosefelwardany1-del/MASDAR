<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B08: whether a rate was used — a payment or a posted journal line in its currency, at that
 * rate, dated while it was the rate in force (from its date until the next rate's). A used rate
 * is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fx_rate_is_used(rate_id bigint) RETURNS boolean AS $$
                WITH r AS (
                    SELECT f.currency, f.rate, f.rate_date,
                           (SELECT MIN(n.rate_date) FROM fx_rates n WHERE n.currency = f.currency AND n.rate_date > f.rate_date) AS until
                    FROM fx_rates f WHERE f.id = rate_id
                )
                SELECT EXISTS (SELECT 1 FROM payments p, r WHERE p.currency = r.currency AND p.rate = r.rate
                                 AND p.paid_on >= r.rate_date AND (r.until IS NULL OR p.paid_on < r.until))
                    OR EXISTS (SELECT 1 FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id, r
                               WHERE e.status = 'posted' AND l.currency = r.currency AND l.rate = r.rate
                                 AND e.entry_date >= r.rate_date AND (r.until IS NULL OR e.entry_date < r.until))
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION fx_rates_delete_guard() RETURNS trigger AS $$
            BEGIN
                IF fx_rate_is_used(OLD.id) THEN
                    RAISE EXCEPTION 'the % rate of % has been used and is never deleted', OLD.currency, OLD.rate_date USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN OLD;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fx_rates_delete_guard BEFORE DELETE ON fx_rates FOR EACH ROW EXECUTE FUNCTION fx_rates_delete_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS fx_rates_delete_guard() CASCADE; DROP FUNCTION IF EXISTS fx_rate_is_used(bigint);');
    }
};
