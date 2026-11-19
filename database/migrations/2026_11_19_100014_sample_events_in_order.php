<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B26: a sample's statuses follow their order (Sample::NEXT): forward along the way or cancelled;
 * approved, rejected and cancelled close it, and only "requested" again reopens it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sample_events_in_order() RETURNS trigger AS $$
            DECLARE
                last_status text;
            BEGIN
                SELECT status INTO last_status FROM sample_events WHERE sample_id = NEW.sample_id ORDER BY id DESC LIMIT 1;
                last_status := COALESCE(last_status, 'requested');
                IF NOT (
                       (last_status = 'requested' AND NEW.status IN ('at_factory', 'received', 'sent', 'cancelled'))
                    OR (last_status = 'at_factory' AND NEW.status IN ('received', 'sent', 'cancelled'))
                    OR (last_status = 'received' AND NEW.status IN ('sent', 'approved', 'rejected', 'cancelled'))
                    OR (last_status = 'sent' AND NEW.status IN ('approved', 'rejected', 'cancelled'))
                    OR (last_status IN ('approved', 'rejected', 'cancelled') AND NEW.status = 'requested')
                    -- the sample's first event, written when it is asked for
                    OR (NEW.status = 'requested' AND NOT EXISTS (SELECT 1 FROM sample_events WHERE sample_id = NEW.sample_id))
                ) THEN
                    RAISE EXCEPTION 'a sample that is % cannot become %', last_status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sample_events_in_order BEFORE INSERT ON sample_events FOR EACH ROW EXECUTE FUNCTION sample_events_in_order();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS sample_events_in_order() CASCADE;');
    }
};
