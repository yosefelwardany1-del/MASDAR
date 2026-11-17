<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module 5 (money): two rules the PHP writers check for the message, held here as the gate.
 *  - A money account's currency never changes once it has payments: every payment is in its
 *    account's currency (payments_guard), so changing it would make the old rows wrong.
 *  - An approval is for an original outgoing payment that has not been reversed. A reversal
 *    is never approved itself: it is effective exactly when the payment it reverses is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION money_accounts_currency_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.currency IS DISTINCT FROM OLD.currency
                   AND EXISTS (SELECT 1 FROM payments WHERE money_account_id = OLD.id) THEN
                    RAISE EXCEPTION 'money account % has payments; its currency never changes', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER money_accounts_currency_guard BEFORE UPDATE ON money_accounts
                FOR EACH ROW EXECUTE FUNCTION money_accounts_currency_guard();

            CREATE OR REPLACE FUNCTION payment_approvals_guard() RETURNS trigger AS $$
            DECLARE
                p payments%ROWTYPE;
            BEGIN
                SELECT * INTO p FROM payments WHERE id = NEW.payment_id;
                IF p.direction <> 'out' THEN
                    RAISE EXCEPTION 'only an outgoing payment is approved' USING ERRCODE = 'check_violation';
                END IF;
                IF p.reverses_payment_id IS NOT NULL THEN
                    RAISE EXCEPTION 'a reversal is not approved; it follows the payment it reverses' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (SELECT 1 FROM payments WHERE reverses_payment_id = p.id) THEN
                    RAISE EXCEPTION 'payment % has been reversed and is not approved', p.number USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_approvals_guard BEFORE INSERT ON payment_approvals
                FOR EACH ROW EXECUTE FUNCTION payment_approvals_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_approvals_guard ON payment_approvals;
            DROP FUNCTION IF EXISTS payment_approvals_guard();
            DROP TRIGGER IF EXISTS money_accounts_currency_guard ON money_accounts;
            DROP FUNCTION IF EXISTS money_accounts_currency_guard();
        SQL);
    }
};
