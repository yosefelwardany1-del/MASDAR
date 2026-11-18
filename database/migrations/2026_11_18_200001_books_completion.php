<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.8, batch C — what complete books need:
 *  - bank statements imported per money account, their lines matched to payments;
 *  - exchange revaluation: a journal line may say which currency's balance it revalues;
 *  - staff expense claims (draft → submitted → approved or rejected), booked when approved and
 *    reimbursed with a payment of kind claim_payment;
 *  - budgets per account and month;
 *  - fixed assets and their monthly depreciation, posted to the books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('expense_claim_prefix', 10)->default('EXP');
            $table->string('asset_prefix', 10)->default('FA');
        });

        // ---- bank reconciliation
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->date('period_from');
            $table->date('period_to');
            // What the bank printed on the statement — its figures, not a balance the system keeps.
            $table->decimal('opening_amount', 18, 4)->nullable();
            $table->decimal('closing_amount', 18, 4)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->foreignId('imported_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['money_account_id', 'period_to']);
        });
        DB::statement('ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_dates_check CHECK (period_to >= period_from)');

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('booked_on');
            $table->text('description')->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 18, 4);
            $table->decimal('running_amount', 18, 4)->nullable();
            $table->unique(['bank_statement_id', 'line_no']);
            $table->index('booked_on');
        });
        DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_check CHECK (amount <> 0)');

        Schema::create('bank_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->unique()->constrained('bank_statement_lines');
            $table->foreignId('payment_id')->unique()->constrained('payments');
            $table->foreignId('matched_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        // ---- exchange revaluation
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->char('revalues_currency', 3)->nullable();
        });

        // ---- expense claims
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->char('currency', 3);
            $table->string('title', 200);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims');
            $table->date('spent_on');
            $table->string('category', 60);
            $table->text('description');
            $table->decimal('amount', 18, 4);
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->foreignId('container_id')->nullable()->constrained('containers');
            $table->timestamps();
            $table->index('expense_claim_id');
        });
        DB::statement('ALTER TABLE expense_claim_lines ADD CONSTRAINT expense_claim_lines_amount_check CHECK (amount > 0)');

        Schema::create('expense_claim_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims');
            $table->string('decision', 12);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['expense_claim_id', 'id']);
        });
        DB::statement("ALTER TABLE expense_claim_decisions ADD CONSTRAINT expense_claim_decisions_check CHECK (decision IN ('submitted', 'approved', 'rejected', 'returned'))");

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('expense_claim_id')->nullable()->constrained('expense_claims');
        });
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_kind_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (
            (kind IN ('client_receipt', 'transfer_in', 'other_in') AND direction = 'in')
            OR (kind IN ('supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out', 'salary_payment', 'claim_payment') AND direction = 'out'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_claim_check CHECK ((kind = 'claim_payment') = (expense_claim_id IS NOT NULL))");

        // ---- budgets
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('year');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['name', 'year']);
        });
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 18, 4);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id', 'month']);
        });
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_check CHECK (month BETWEEN 1 AND 12 AND amount >= 0)');

        // ---- fixed assets
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 160);
            $table->date('acquired_on');
            $table->decimal('cost', 18, 4);
            $table->decimal('residual_value', 18, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->date('depreciation_starts_on');
            $table->foreignId('asset_account_id')->constrained('accounts');
            $table->foreignId('depreciation_account_id')->constrained('accounts');
            $table->foreignId('expense_account_id')->constrained('accounts');
            $table->string('location', 120)->nullable();
            $table->date('disposed_on')->nullable();
            $table->text('disposal_note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_check CHECK (
            cost > 0 AND residual_value >= 0 AND residual_value < cost AND useful_life_months BETWEEN 1 AND 600
            AND depreciation_starts_on >= date_trunc(\'month\', acquired_on)::date AND EXTRACT(DAY FROM depreciation_starts_on) = 1 AND (disposed_on IS NULL OR disposed_on >= acquired_on))');

        Schema::create('depreciation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->date('month');
            $table->decimal('amount', 18, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['fixed_asset_id', 'month']);
        });
        DB::statement('ALTER TABLE depreciation_lines ADD CONSTRAINT depreciation_lines_check CHECK (amount > 0 AND EXTRACT(DAY FROM month) = 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_statement_lines_frozen BEFORE UPDATE ON bank_statement_lines
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER expense_claim_decisions_append_only BEFORE UPDATE OR DELETE ON expense_claim_decisions
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER depreciation_lines_append_only BEFORE UPDATE OR DELETE ON depreciation_lines
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER fixed_assets_code_frozen BEFORE UPDATE ON fixed_assets
                FOR EACH ROW EXECUTE FUNCTION masdar_code_is_frozen();

            -- A statement line matches a payment of the same money account and the same signed amount.
            CREATE OR REPLACE FUNCTION bank_matches_guard() RETURNS trigger AS $$
            DECLARE
                line_amount numeric;
                line_account bigint;
                pay payments%ROWTYPE;
            BEGIN
                SELECT l.amount, s.money_account_id INTO line_amount, line_account
                    FROM bank_statement_lines l JOIN bank_statements s ON s.id = l.bank_statement_id WHERE l.id = NEW.bank_statement_line_id;
                SELECT * INTO pay FROM payments WHERE id = NEW.payment_id;
                IF pay.money_account_id <> line_account OR pay.signed_amount <> line_amount THEN
                    RAISE EXCEPTION 'a statement line matches a payment of its account and amount' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER bank_matches_guard BEFORE INSERT ON bank_matches FOR EACH ROW EXECUTE FUNCTION bank_matches_guard();

            -- A claim changes only while the claimant has it (no decision yet, or returned to them).
            CREATE OR REPLACE FUNCTION expense_claim_lines_guard() RETURNS trigger AS $$
            DECLARE
                latest text;
            BEGIN
                SELECT decision INTO latest FROM expense_claim_decisions
                    WHERE expense_claim_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.expense_claim_id ELSE NEW.expense_claim_id END
                    ORDER BY id DESC LIMIT 1;
                IF latest IS NOT NULL AND latest <> 'returned' THEN
                    RAISE EXCEPTION 'a submitted claim never changes; it is returned to its claimant to change it' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER expense_claim_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON expense_claim_lines
                FOR EACH ROW EXECUTE FUNCTION expense_claim_lines_guard();

            -- Decisions in order, and never on one's own claim.
            CREATE OR REPLACE FUNCTION expense_claim_decisions_guard() RETURNS trigger AS $$
            DECLARE
                latest text;
                claimant bigint;
            BEGIN
                SELECT decision INTO latest FROM expense_claim_decisions WHERE expense_claim_id = NEW.expense_claim_id ORDER BY id DESC LIMIT 1;
                SELECT user_id INTO claimant FROM expense_claims WHERE id = NEW.expense_claim_id;
                IF NEW.decision = 'submitted' AND latest IS NOT NULL AND latest <> 'returned' THEN
                    RAISE EXCEPTION 'only a draft or returned claim is submitted' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.decision IN ('approved', 'rejected', 'returned') THEN
                    IF latest IS DISTINCT FROM 'submitted' THEN
                        RAISE EXCEPTION 'only a submitted claim is decided' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.user_id = claimant THEN
                        RAISE EXCEPTION 'nobody decides their own claim' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER expense_claim_decisions_guard BEFORE INSERT ON expense_claim_decisions
                FOR EACH ROW EXECUTE FUNCTION expense_claim_decisions_guard();

            -- A claim is paid only once approved, from an account in its currency.
            CREATE OR REPLACE FUNCTION payments_claim_guard() RETURNS trigger AS $$
            DECLARE
                latest text;
                claim_currency text;
            BEGIN
                IF NEW.expense_claim_id IS NULL OR NEW.reverses_payment_id IS NOT NULL THEN
                    RETURN NEW;
                END IF;
                SELECT decision INTO latest FROM expense_claim_decisions WHERE expense_claim_id = NEW.expense_claim_id ORDER BY id DESC LIMIT 1;
                SELECT currency INTO claim_currency FROM expense_claims WHERE id = NEW.expense_claim_id;
                IF latest IS DISTINCT FROM 'approved' THEN
                    RAISE EXCEPTION 'only an approved expense claim is paid' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.currency <> claim_currency THEN
                    RAISE EXCEPTION 'a claim is paid in its own currency' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payments_claim_guard BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payments_claim_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_claim_guard ON payments; DROP FUNCTION IF EXISTS payments_claim_guard();
            DROP FUNCTION IF EXISTS expense_claim_decisions_guard() CASCADE; DROP FUNCTION IF EXISTS expense_claim_lines_guard() CASCADE;
            DROP FUNCTION IF EXISTS bank_matches_guard() CASCADE;');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_claim_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_kind_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (
            (kind IN ('client_receipt', 'transfer_in', 'other_in') AND direction = 'in')
            OR (kind IN ('supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out', 'salary_payment') AND direction = 'out'))");
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('expense_claim_id'));
        foreach (['depreciation_lines', 'fixed_assets', 'budget_lines', 'budgets', 'expense_claim_decisions', 'expense_claim_lines', 'expense_claims', 'bank_matches', 'bank_statement_lines', 'bank_statements'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropColumn('revalues_currency'));
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn(['expense_claim_prefix', 'asset_prefix']));
    }
};
