<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B15: a container's history is a possible one. Besides moving forward only (I8), a status that is
 * not a correction is recorded once, is not dated before the status before it, and — from
 * "Loaded & sealed" on — needs goods loaded in the container. (That it is not in the future is the
 * writer's check, by the company's clock.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION container_events_in_order() RETURNS trigger AS $$
            DECLARE
                new_sort integer;
                last_row record;
                sealed_sort integer;
            BEGIN
                IF NEW.corrects_event_id IS NOT NULL THEN
                    RETURN NEW;
                END IF;
                SELECT sort INTO new_sort FROM lookups WHERE list = 'container_status' AND code = NEW.status;
                SELECT l.sort, e.happened_at INTO last_row FROM container_events e
                    JOIN lookups l ON l.list = 'container_status' AND l.code = e.status
                    WHERE e.container_id = NEW.container_id ORDER BY e.id DESC LIMIT 1;
                IF FOUND THEN
                    IF new_sort = last_row.sort THEN
                        RAISE EXCEPTION 'container status % is already recorded', NEW.status USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.happened_at < last_row.happened_at THEN
                        RAISE EXCEPTION 'a container status is not dated before the status before it' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                SELECT sort INTO sealed_sort FROM lookups WHERE list = 'container_status' AND code = 'loaded_sealed';
                IF sealed_sort IS NOT NULL AND new_sort >= sealed_sort
                   AND (SELECT COALESCE(SUM(cartons), 0) FROM container_loads WHERE container_id = NEW.container_id) <= 0 THEN
                    RAISE EXCEPTION 'nothing is loaded in this container yet' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER container_events_in_order BEFORE INSERT ON container_events
                FOR EACH ROW EXECUTE FUNCTION container_events_in_order();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS container_events_in_order() CASCADE;');
    }
};
