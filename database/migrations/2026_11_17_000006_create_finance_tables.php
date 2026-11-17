<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §3.5, §3.6, §5.6: the chart of accounts, MASDAR's money accounts, FX rates, payments
 * (append-only; a correction is a reversal row), and the ledger. A journal entry is built as a
 * draft and posted once: posting checks it balances (I1) and is outside a closed period (I3);
 * a posted entry and its lines never change (I2). Every line carries its currency and rate (I7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 160);
            $table->string('name_ar', 160)->nullable();
            $table->string('name_zh', 160)->nullable();
            $table->string('type', 20);
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->boolean('is_postable')->default(true);
            $table->char('currency', 3)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");

        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->char('currency', 3);
            $table->string('location', 20);
            $table->foreignId('ledger_account_id')->nullable()->constrained('accounts');
            $table->text('details')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_kind_check CHECK (kind IN ('bank', 'ewallet', 'cash', 'agent'))");
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_location_check CHECK (location IN ('hong_kong', 'mainland', 'other'))");

        // Units of the book currency for one unit of `currency` on `rate_date`.
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 6);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['currency', 'rate_date']);
        });
        DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_rate_check CHECK (rate > 0)');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('paid_on');
            $table->string('kind', 30);
            $table->string('direction', 3);
            $table->foreignId('money_account_id')->constrained('money_accounts');
            $table->char('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->decimal('rate', 18, 6);
            $table->decimal('book_amount', 18, 4)->storedAs('round(amount * rate, 4)');
            // Cash movement with its sign: in is +, out is −, a reversal flips it.
            $table->decimal('signed_amount', 18, 4)->storedAs(
                "round(amount * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 4)"
            );
            $table->decimal('signed_book_amount', 18, 4)->storedAs(
                "round(amount * rate * (CASE WHEN direction = 'in' THEN 1 ELSE -1 END) * (CASE WHEN reverses_payment_id IS NULL THEN 1 ELSE -1 END), 4)"
            );
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices');
            $table->foreignId('supplier_order_id')->nullable()->constrained('supplier_orders');
            $table->foreignId('container_id')->nullable()->constrained('containers');
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts');
            $table->uuid('transfer_group')->nullable()->index();
            $table->foreignId('reverses_payment_id')->nullable()->unique()->constrained('payments');
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['party_id', 'paid_on']);
            $table->index('proforma_invoice_id');
            $table->index('supplier_order_id');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (
            (kind IN ('client_receipt', 'transfer_in', 'other_in') AND direction = 'in')
            OR (kind IN ('supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out') AND direction = 'out'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0 AND rate > 0)');

        Schema::create('payment_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments');
            $table->foreignId('approved_by')->constrained('users');
            $table->timestampTz('approved_at')->useCurrent();
            $table->text('note')->nullable();
        });

        Schema::table('supplier_order_step_events', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments');
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_dates_check CHECK (ends_on >= starts_on)');

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('entry_date');
            $table->string('status', 10)->default('draft');
            $table->string('event', 40)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('journal_entries');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestampTz('posted_at')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('draft', 'posted'))");
        DB::statement('CREATE UNIQUE INDEX journal_entries_one_posting_per_source ON journal_entries (source_type, source_id, event) WHERE source_type IS NOT NULL AND reverses_entry_id IS NULL');

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->char('currency', 3);
            $table->decimal('amount_currency', 18, 4);
            $table->decimal('rate', 18, 6);
            $table->string('memo', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['account_id', 'journal_entry_id']);
            $table->index('party_id');
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amounts_check CHECK (
            debit >= 0 AND credit >= 0 AND ((debit > 0) <> (credit > 0)) AND amount_currency > 0 AND rate > 0)');

        // Which accounts each automatic posting uses; the money side comes from the money account.
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40)->unique();
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts');
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER payments_append_only BEFORE UPDATE OR DELETE ON payments
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER payment_approvals_append_only BEFORE UPDATE OR DELETE ON payment_approvals
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            CREATE OR REPLACE FUNCTION payments_guard() RETURNS trigger AS $$
            DECLARE
                original payments%ROWTYPE;
            BEGIN
                IF NEW.currency <> (SELECT currency FROM money_accounts WHERE id = NEW.money_account_id) THEN
                    RAISE EXCEPTION 'a payment is in its money account''s currency' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.reverses_payment_id IS NOT NULL THEN
                    SELECT * INTO original FROM payments WHERE id = NEW.reverses_payment_id;
                    IF original.reverses_payment_id IS NOT NULL THEN
                        RAISE EXCEPTION 'a reversal cannot itself be reversed' USING ERRCODE = 'check_violation';
                    END IF;
                    IF (NEW.kind, NEW.direction, NEW.money_account_id, NEW.amount, NEW.currency)
                       IS DISTINCT FROM (original.kind, original.direction, original.money_account_id, original.amount, original.currency) THEN
                        RAISE EXCEPTION 'a reversal repeats the payment it reverses' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payments_guard BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payments_guard();

            -- I1, I2, I3: a posted entry never changes; posting checks balance and period.
            CREATE OR REPLACE FUNCTION journal_entries_guard() RETURNS trigger AS $$
            DECLARE
                total_debit numeric;
                total_credit numeric;
                line_count integer;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'posted' THEN
                        RAISE EXCEPTION 'a posted journal entry is never deleted; reverse it' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'a journal entry is created as a draft and then posted' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status = 'posted' THEN
                    RAISE EXCEPTION 'a posted journal entry never changes; reverse it' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status = 'posted' THEN
                    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0), COUNT(*)
                        INTO total_debit, total_credit, line_count
                        FROM journal_lines WHERE journal_entry_id = NEW.id;
                    IF line_count < 2 OR total_debit <> total_credit OR total_debit = 0 THEN
                        RAISE EXCEPTION 'journal entry % does not balance (debit %, credit %)', NEW.number, total_debit, total_credit
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM accounting_periods WHERE closed_at IS NOT NULL AND NEW.entry_date BETWEEN starts_on AND ends_on) THEN
                        RAISE EXCEPTION 'the period containing % is closed', NEW.entry_date USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_entries_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_entries_guard();

            CREATE OR REPLACE FUNCTION journal_lines_guard() RETURNS trigger AS $$
            DECLARE
                entry_status text;
            BEGIN
                SELECT status INTO entry_status FROM journal_entries
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_entry_id ELSE NEW.journal_entry_id END;
                IF entry_status = 'posted' THEN
                    RAISE EXCEPTION 'the lines of a posted journal entry never change' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP <> 'DELETE' AND NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.account_id AND is_postable AND active) THEN
                    RAISE EXCEPTION 'account % is a heading or inactive; post to one of its sub-accounts', NEW.account_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_lines_guard();
        SQL);
    }

    public function down(): void
    {
        foreach (['posting_rules', 'journal_lines', 'journal_entries', 'accounting_periods'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('supplier_order_step_events', fn (Blueprint $table) => $table->dropForeign(['payment_id']));
        foreach (['payment_approvals', 'payments', 'fx_rates', 'money_accounts', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS journal_lines_guard(); DROP FUNCTION IF EXISTS journal_entries_guard(); DROP FUNCTION IF EXISTS payments_guard();');
    }
};
