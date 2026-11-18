<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §5.7 HR: the employee file (with its salary history and documents, append-only), leave
 * (requests and their decisions, append-only; allowances per year), attendance (append-only,
 * the latest record of a day counts), public holidays, and monthly payroll runs whose lines
 * never change once the run is approved. Salaries are paid as payments of kind salary_payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('employee_prefix', 10)->default('EMP');
            $table->string('payroll_prefix', 10)->default('PR');
            $table->char('payroll_currency', 3)->default('CNY');
            $table->unsignedSmallInteger('hr_document_warning_days')->default(60);
            // ISO weekdays (1 = Monday … 7 = Sunday) that are working days.
            $table->string('hr_work_days', 20)->default('1,2,3,4,5');
            $table->decimal('payroll_days_per_month', 6, 2)->default(21.75);
            $table->decimal('payroll_overtime_multiplier', 6, 2)->default(1.50);
            // Social insurance and housing fund: OPEN (Q-H3), so no rate is assumed.
            $table->decimal('si_employee_percent', 6, 3)->nullable();
            $table->decimal('si_employer_percent', 6, 3)->nullable();
            $table->decimal('hf_employee_percent', 6, 3)->nullable();
            $table->decimal('hf_employer_percent', 6, 3)->nullable();
            $table->decimal('si_base_min', 18, 4)->nullable();
            $table->decimal('si_base_max', 18, 4)->nullable();
        });
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_hr_check CHECK (
            hr_document_warning_days <= 365 AND payroll_days_per_month > 0 AND payroll_overtime_multiplier >= 1
            AND COALESCE(si_employee_percent, 0) BETWEEN 0 AND 100 AND COALESCE(si_employer_percent, 0) BETWEEN 0 AND 100
            AND COALESCE(hf_employee_percent, 0) BETWEEN 0 AND 100 AND COALESCE(hf_employer_percent, 0) BETWEEN 0 AND 100
            AND COALESCE(si_base_min, 0) >= 0 AND (si_base_max IS NULL OR si_base_max >= COALESCE(si_base_min, 0)))');

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('name_local', 200)->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');
            $table->string('job_title', 120)->nullable();
            $table->string('department', 40)->nullable();
            $table->string('work_location', 40)->nullable();
            $table->string('employment_type', 40)->nullable();
            $table->string('nationality', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->string('id_number', 80)->nullable();
            $table->text('bank_details')->nullable();
            $table->char('salary_currency', 3)->default('CNY');
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('left_on');
        });
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_dates_check CHECK (left_on IS NULL OR left_on >= joined_on)');

        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('effective_from');
            $table->decimal('base_salary', 18, 4);
            $table->decimal('allowances', 18, 4)->default(0);
            $table->char('currency', 3);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE employee_salaries ADD CONSTRAINT employee_salaries_amounts_check CHECK (base_salary > 0 AND allowances >= 0)');

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('kind', 40);
            $table->string('number', 80)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'kind']);
            $table->index('expires_on');
        });
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_dates_check CHECK (expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on)');

        Schema::create('leave_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->unsignedSmallInteger('year');
            $table->string('leave_type', 40);
            $table->decimal('days', 6, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'year', 'leave_type']);
        });
        DB::statement('ALTER TABLE leave_allowances ADD CONSTRAINT leave_allowances_days_check CHECK (days >= 0 AND days <= 366 AND year BETWEEN 2000 AND 2100)');

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('leave_type', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 6, 2);
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'starts_on']);
        });
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_check CHECK (
            ends_on >= starts_on AND days > 0 AND days <= (ends_on - starts_on + 1) AND (days * 2) = floor(days * 2))');

        Schema::create('leave_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests');
            $table->string('decision', 12);
            $table->foreignId('decided_by')->constrained('users');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('leave_request_id');
        });
        DB::statement("ALTER TABLE leave_decisions ADD CONSTRAINT leave_decisions_decision_check CHECK (decision IN ('approved', 'rejected', 'cancelled'))");

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('work_date');
            $table->string('status', 12);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'work_date']);
            $table->index('work_date');
        });
        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_check CHECK (
            status IN ('present', 'absent', 'half_day', 'late', 'remote') AND overtime_hours >= 0 AND overtime_hours <= 24)");

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_on')->unique();
            $table->string('name', 160);
            // A make-up working day on a weekend (China's 调休) rather than a day off.
            $table->boolean('is_workday')->default(false);
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('period');
            $table->char('currency', 3);
            $table->string('status', 10)->default('draft');
            $table->date('pay_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index('period');
        });
        DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_check CHECK (
            status IN ('draft', 'approved', 'void') AND extract(day FROM period) = 1
            AND (status = 'draft' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
            AND (status <> 'void' OR (voided_by IS NOT NULL AND voided_at IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_per_month ON payroll_runs (period, currency) WHERE status <> 'void'");

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs');
            $table->foreignId('employee_id')->constrained('employees');
            $table->decimal('base_salary', 18, 4)->default(0);
            $table->decimal('allowances', 18, 4)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('overtime_pay', 18, 4)->default(0);
            $table->decimal('bonus', 18, 4)->default(0);
            $table->decimal('unpaid_days', 6, 2)->default(0);
            $table->decimal('unpaid_deduction', 18, 4)->default(0);
            $table->decimal('social_insurance_employee', 18, 4)->default(0);
            $table->decimal('housing_fund_employee', 18, 4)->default(0);
            $table->decimal('income_tax', 18, 4)->default(0);
            $table->decimal('other_deductions', 18, 4)->default(0);
            $table->decimal('social_insurance_employer', 18, 4)->default(0);
            $table->decimal('housing_fund_employer', 18, 4)->default(0);
            $table->decimal('gross', 18, 4)->storedAs('base_salary + allowances + overtime_pay + bonus - unpaid_deduction');
            $table->decimal('net', 18, 4)->storedAs(
                'base_salary + allowances + overtime_pay + bonus - unpaid_deduction - social_insurance_employee - housing_fund_employee - income_tax - other_deductions'
            );
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
        DB::statement('ALTER TABLE payroll_lines ADD CONSTRAINT payroll_lines_amounts_check CHECK (
            base_salary >= 0 AND allowances >= 0 AND overtime_hours >= 0 AND overtime_pay >= 0 AND bonus >= 0
            AND unpaid_days >= 0 AND unpaid_deduction >= 0 AND social_insurance_employee >= 0 AND housing_fund_employee >= 0
            AND income_tax >= 0 AND other_deductions >= 0 AND social_insurance_employer >= 0 AND housing_fund_employer >= 0
            AND base_salary + allowances + overtime_pay + bonus - unpaid_deduction >= 0
            AND base_salary + allowances + overtime_pay + bonus - unpaid_deduction
                - social_insurance_employee - housing_fund_employee - income_tax - other_deductions >= 0)');

        // Salaries are paid against a run.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->index('payroll_run_id');
        });
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_kind_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (
            (kind IN ('client_receipt', 'transfer_in', 'other_in') AND direction = 'in')
            OR (kind IN ('supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out', 'salary_payment') AND direction = 'out'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_salary_run_check CHECK ((kind = 'salary_payment') = (payroll_run_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER employees_code_frozen BEFORE UPDATE ON employees FOR EACH ROW EXECUTE FUNCTION masdar_code_is_frozen();

            CREATE TRIGGER employee_salaries_append_only BEFORE UPDATE OR DELETE ON employee_salaries
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER employee_documents_append_only BEFORE UPDATE OR DELETE ON employee_documents
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER leave_allowances_append_only BEFORE UPDATE OR DELETE ON leave_allowances
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER leave_requests_append_only BEFORE UPDATE OR DELETE ON leave_requests
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER leave_decisions_append_only BEFORE UPDATE OR DELETE ON leave_decisions
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();
            CREATE TRIGGER attendance_records_append_only BEFORE UPDATE OR DELETE ON attendance_records
                FOR EACH ROW EXECUTE FUNCTION masdar_append_only();

            -- A request is decided once (approved or rejected) while pending; it can be
            -- cancelled while pending or approved; nothing follows a rejection or a cancellation.
            -- Nobody approves or rejects their own request.
            CREATE OR REPLACE FUNCTION leave_decisions_guard() RETURNS trigger AS $$
            DECLARE
                latest text;
                own_user bigint;
            BEGIN
                SELECT d.decision INTO latest FROM leave_decisions d
                    WHERE d.leave_request_id = NEW.leave_request_id ORDER BY d.id DESC LIMIT 1;
                IF latest IN ('rejected', 'cancelled') THEN
                    RAISE EXCEPTION 'a % leave request takes no further decision', latest USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.decision IN ('approved', 'rejected') AND latest IS NOT NULL THEN
                    RAISE EXCEPTION 'a leave request is approved or rejected only while it is pending' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.decision IN ('approved', 'rejected') THEN
                    SELECT e.user_id INTO own_user FROM leave_requests r JOIN employees e ON e.id = r.employee_id
                        WHERE r.id = NEW.leave_request_id;
                    IF own_user IS NOT NULL AND own_user = NEW.decided_by THEN
                        RAISE EXCEPTION 'nobody decides their own leave request' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER leave_decisions_guard BEFORE INSERT ON leave_decisions FOR EACH ROW EXECUTE FUNCTION leave_decisions_guard();

            -- A draft run is edited freely and may be deleted; an approved run never changes
            -- except to be voided; a void run never changes.
            CREATE OR REPLACE FUNCTION payroll_runs_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'only a draft payroll run is deleted' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'void' THEN
                    RAISE EXCEPTION 'a void payroll run never changes' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'approved' THEN
                    IF NEW.status <> 'void'
                       OR (NEW.number, NEW.period, NEW.currency, NEW.pay_date, NEW.note, NEW.created_by, NEW.approved_by, NEW.approved_at)
                          IS DISTINCT FROM (OLD.number, OLD.period, OLD.currency, OLD.pay_date, OLD.note, OLD.created_by, OLD.approved_by, OLD.approved_at) THEN
                        RAISE EXCEPTION 'an approved payroll run never changes; void it to run the month again' USING ERRCODE = 'restrict_violation';
                    END IF;
                    -- Voided only once nothing paid against it still counts and its entry is reversed.
                    IF COALESCE((SELECT SUM(p.signed_amount) FROM payments p WHERE p.payroll_run_id = OLD.id), 0) <> 0 THEN
                        RAISE EXCEPTION 'reverse the salary payments of payroll run % before voiding it', OLD.number USING ERRCODE = 'check_violation';
                    END IF;
                    IF EXISTS (SELECT 1 FROM journal_entries e
                               WHERE e.source_type = 'payroll_run' AND e.source_id = OLD.id AND e.reverses_entry_id IS NULL
                                 AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_entry_id = e.id)) THEN
                        RAISE EXCEPTION 'reverse the journal entry of payroll run % before voiding it', OLD.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF OLD.status = 'draft' AND NEW.status = 'void' THEN
                    RAISE EXCEPTION 'a draft payroll run is deleted, not voided' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.period, NEW.currency) IS DISTINCT FROM (OLD.period, OLD.currency) THEN
                    RAISE EXCEPTION 'a payroll run keeps its month and currency' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'draft' AND NEW.status = 'approved'
                   AND NOT EXISTS (SELECT 1 FROM payroll_lines l WHERE l.payroll_run_id = NEW.id) THEN
                    RAISE EXCEPTION 'a payroll run without lines is not approved' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payroll_runs_guard BEFORE UPDATE OR DELETE ON payroll_runs FOR EACH ROW EXECUTE FUNCTION payroll_runs_guard();

            -- Lines change only while their run is a draft, and belong to an employee paid in the run's currency.
            CREATE OR REPLACE FUNCTION payroll_lines_guard() RETURNS trigger AS $$
            DECLARE
                run_status text;
            BEGIN
                SELECT status INTO run_status FROM payroll_runs
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.payroll_run_id ELSE NEW.payroll_run_id END;
                IF run_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'the lines of an approved or void payroll run never change' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.payroll_run_id, NEW.employee_id) IS DISTINCT FROM (OLD.payroll_run_id, OLD.employee_id) THEN
                    RAISE EXCEPTION 'a payroll line keeps its run and employee' USING ERRCODE = 'check_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payroll_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON payroll_lines
                FOR EACH ROW EXECUTE FUNCTION payroll_lines_guard();

            -- A salary is paid only against an approved run, in the run's currency.
            CREATE OR REPLACE FUNCTION payments_salary_guard() RETURNS trigger AS $$
            DECLARE
                run payroll_runs%ROWTYPE;
            BEGIN
                IF NEW.payroll_run_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO run FROM payroll_runs WHERE id = NEW.payroll_run_id;
                IF NEW.reverses_payment_id IS NULL AND run.status <> 'approved' THEN
                    RAISE EXCEPTION 'salaries are paid only against an approved payroll run' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.currency <> run.currency THEN
                    RAISE EXCEPTION 'a salary payment is in its payroll run''s currency' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payments_salary_guard BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payments_salary_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payments_salary_guard ON payments;
            DROP FUNCTION IF EXISTS payments_salary_guard();
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_salary_run_check;
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_kind_check;
            ALTER TABLE payments ADD CONSTRAINT payments_kind_check CHECK (
                (kind IN ('client_receipt', 'transfer_in', 'other_in') AND direction = 'in')
                OR (kind IN ('supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out') AND direction = 'out'));
            DROP FUNCTION IF EXISTS leave_decisions_guard() CASCADE;
            DROP FUNCTION IF EXISTS payroll_runs_guard() CASCADE;
            DROP FUNCTION IF EXISTS payroll_lines_guard() CASCADE;
        SQL);
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
        });
        foreach (['payroll_lines', 'payroll_runs', 'public_holidays', 'attendance_records', 'leave_decisions', 'leave_requests',
            'leave_allowances', 'employee_documents', 'employee_salaries', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE business_settings DROP CONSTRAINT IF EXISTS business_settings_hr_check');
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['employee_prefix', 'payroll_prefix', 'payroll_currency', 'hr_document_warning_days', 'hr_work_days',
                'payroll_days_per_month', 'payroll_overtime_multiplier', 'si_employee_percent', 'si_employer_percent',
                'hf_employee_percent', 'hf_employer_percent', 'si_base_min', 'si_base_max']);
        });
    }
};
