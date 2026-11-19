<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B06: money leaving an account never takes it below zero, unless the account is one that may
 * (allow_negative, off by default: e.g. a payment agent who advances the money). "What it holds"
 * here is what has arrived — every row, money out awaiting approval counted as gone, a transfer
 * in counted once its outgoing row is approved — so nothing awaiting approval can overdraw it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->boolean('allow_negative')->default(false);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION money_account_available(account bigint) RETURNS numeric AS $$
                SELECT COALESCE(SUM(p.signed_amount), 0) FROM payments p
                WHERE p.money_account_id = account
                  AND NOT (p.kind = 'transfer_in'
                       AND COALESCE((SELECT payments_out_require_approval FROM business_settings WHERE id = 1), TRUE)
                       AND NOT EXISTS (SELECT 1 FROM payments o JOIN payment_approvals a ON a.payment_id = o.id
                                       WHERE o.transfer_group = p.transfer_group AND o.kind = 'transfer_out'
                                         AND (o.reverses_payment_id IS NULL) = (p.reverses_payment_id IS NULL)))
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION payments_never_overdraw() RETURNS trigger AS $$
            BEGIN
                IF NEW.signed_amount < 0
                   AND NOT (SELECT allow_negative FROM money_accounts WHERE id = NEW.money_account_id)
                   AND money_account_available(NEW.money_account_id) < 0 THEN
                    RAISE EXCEPTION 'payment % would take its money account below zero', NEW.number USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payments_never_overdraw AFTER INSERT ON payments
                FOR EACH ROW EXECUTE FUNCTION payments_never_overdraw();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS payments_never_overdraw() CASCADE; DROP FUNCTION IF EXISTS money_account_available(bigint);');
        Schema::table('money_accounts', fn (Blueprint $table) => $table->dropColumn('allow_negative'));
    }
};
