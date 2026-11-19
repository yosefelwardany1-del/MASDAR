<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B29: entries that make accounting sense.
 *
 * - A manual entry moves an amount between two accounts, or between two parties of one account
 *   (moving a receipt from one client to another). One whose every line is on the same account
 *   and party is not posted. Reversals are exempt: an entry posted before this rule can still be
 *   corrected.
 * - The counter account of an expense, other money in or other money out is of a type that fits
 *   the kind (Lists & options), and never a money account's own ledger account: moving money
 *   between own accounts is a transfer. A reversal repeats its payment and is exempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('counter_types_expense', 60)->default('expense');
            $table->string('counter_types_other_in', 60)->default('income,liability,equity');
            $table->string('counter_types_other_out', 60)->default('asset,liability,equity');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entries_two_sides() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'posted' AND OLD.status = 'draft' AND NEW.source_type IS NULL AND NEW.reverses_entry_id IS NULL
                   AND (SELECT COUNT(DISTINCT (account_id, COALESCE(party_id, 0))) FROM journal_lines WHERE journal_entry_id = NEW.id) < 2 THEN
                    RAISE EXCEPTION 'journal entry % has every line on one account', NEW.number USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER journal_entries_two_sides BEFORE UPDATE OF status ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_entries_two_sides();

            CREATE OR REPLACE FUNCTION payments_counter_account_fits() RETURNS trigger AS $$
            DECLARE
                account_type text;
                allowed text;
            BEGIN
                IF NEW.counter_account_id IS NULL OR NEW.reverses_payment_id IS NOT NULL THEN
                    RETURN NEW;
                END IF;
                IF EXISTS (SELECT 1 FROM money_accounts WHERE ledger_account_id = NEW.counter_account_id) THEN
                    RAISE EXCEPTION 'counter account % is a money account''s own ledger account', NEW.counter_account_id USING ERRCODE = 'check_violation';
                END IF;
                SELECT type INTO account_type FROM accounts WHERE id = NEW.counter_account_id;
                SELECT CASE NEW.kind WHEN 'expense' THEN counter_types_expense WHEN 'other_in' THEN counter_types_other_in
                           WHEN 'other_out' THEN counter_types_other_out END
                    INTO allowed FROM business_settings WHERE id = 1;
                allowed := COALESCE(allowed, CASE NEW.kind WHEN 'expense' THEN 'expense' WHEN 'other_in' THEN 'income,liability,equity'
                                                           WHEN 'other_out' THEN 'asset,liability,equity' ELSE '' END);
                IF NOT (account_type = ANY (string_to_array(allowed, ','))) THEN
                    RAISE EXCEPTION 'counter account % does not fit a payment of kind %', NEW.counter_account_id, NEW.kind USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payments_counter_account_fits BEFORE INSERT ON payments
                FOR EACH ROW EXECUTE FUNCTION payments_counter_account_fits();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS payments_counter_account_fits() CASCADE; DROP FUNCTION IF EXISTS journal_entries_two_sides() CASCADE;');
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn(['counter_types_expense', 'counter_types_other_in', 'counter_types_other_out']));
    }
};
