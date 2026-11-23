<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B16: a payment is approved by someone other than the person who recorded it, as an expense
 * claim is. A company with a single manager switches this off on Lists & options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->boolean('payments_approved_by_another')->default(true);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_approvals_not_own() RETURNS trigger AS $$
            BEGIN
                IF COALESCE((SELECT payments_approved_by_another FROM business_settings WHERE id = 1), TRUE)
                   AND EXISTS (SELECT 1 FROM payments WHERE id = NEW.payment_id AND user_id = NEW.approved_by) THEN
                    RAISE EXCEPTION 'a payment is approved by someone other than the person who recorded it' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payment_approvals_not_own BEFORE INSERT ON payment_approvals FOR EACH ROW EXECUTE FUNCTION payment_approvals_not_own();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS payment_approvals_not_own() CASCADE;');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn('payments_approved_by_another'));
    }
};
