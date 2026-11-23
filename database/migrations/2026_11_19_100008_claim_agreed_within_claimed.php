<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** B14: the amount agreed on a claim is above zero and never more than the amount claimed. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION claim_events_agreed_guard() RETURNS trigger AS $$
            DECLARE
                claimed numeric;
            BEGIN
                IF NEW.agreed_amount IS NOT NULL THEN
                    IF NEW.agreed_amount <= 0 THEN
                        RAISE EXCEPTION 'the amount agreed is above zero' USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT claimed_amount INTO claimed FROM claims WHERE id = NEW.claim_id;
                    IF claimed IS NOT NULL AND NEW.agreed_amount > claimed THEN
                        RAISE EXCEPTION 'the amount agreed (%) is more than the amount claimed (%)', NEW.agreed_amount, claimed USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER claim_events_agreed_guard BEFORE INSERT ON claim_events FOR EACH ROW EXECUTE FUNCTION claim_events_agreed_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS claim_events_agreed_guard() CASCADE;');
    }
};
