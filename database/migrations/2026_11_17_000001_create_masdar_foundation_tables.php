<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What every MASDAR module shares: the business options (one row), the editable lists that
 * stand in for every OPEN choice in the spec, the gapless numbering, the order steps (S2) and
 * the append-only guard used by every log table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The guard every append-only table uses (CLAUDE.md: corrections are new rows).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION masdar_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'append-only: rows of % are never updated or deleted; record a correction instead', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IS NULL OR locale IN ('ar', 'en', 'zh_CN'))");

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->text('company_address')->nullable();
            $table->string('company_phone', 120)->nullable();
            $table->char('pi_currency', 3)->default('USD');
            $table->char('supplier_currency', 3)->default('CNY');
            $table->char('book_currency', 3)->default('USD');
            $table->decimal('default_deposit_percent', 5, 2)->default(30);
            $table->string('pi_terms_template', 255)->default('{deposit}% DEPOSIT {final}% FINAL PAYMENT BEFORE SHIPPING');
            $table->boolean('pi_issue_requires_approval')->default(true);
            $table->boolean('payments_out_require_approval')->default(true);
            $table->boolean('sales_see_supplier_prices')->default(false);
            $table->boolean('sales_own_clients_only')->default(true);
            $table->boolean('block_loading_unpaid')->default(false);
            $table->unsignedSmallInteger('step_warning_days')->default(2);
            $table->unsignedSmallInteger('eta_warning_days')->default(3);
            $table->string('client_prefix', 10)->default('C');
            $table->string('supplier_prefix', 10)->default('S');
            $table->string('shipping_company_prefix', 10)->default('SC');
            $table->string('product_prefix', 10)->default('P');
            $table->string('pi_prefix', 10)->default('PI');
            $table->string('supplier_order_prefix', 10)->default('SO');
            $table->string('shipment_prefix', 10)->default('SH');
            $table->string('payment_prefix', 10)->default('PAY');
            $table->string('journal_prefix', 10)->default('JE');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_single_row CHECK (id = 1)');
        DB::statement('ALTER TABLE business_settings ADD CONSTRAINT business_settings_deposit_check CHECK (default_deposit_percent BETWEEN 0 AND 100)');

        // Every editable list (units, container types, ports, currencies, statuses …).
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('list', 40);
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('name_ar', 160)->nullable();
            $table->string('name_zh', 160)->nullable();
            $table->jsonb('meta')->default('{}');
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['list', 'code']);
        });
        DB::statement("ALTER TABLE lookups ADD CONSTRAINT lookups_code_check CHECK (code ~ '^[A-Za-z0-9_.-]+$')");

        // Gapless numbers per series and year; a row is locked while a number is taken.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('series', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['series', 'year']);
        });

        // The steps of a supplier order (S2's columns), editable on the Lists & options screen.
        Schema::create('order_step_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->string('name_ar', 160)->nullable();
            $table->string('name_zh', 160)->nullable();
            $table->integer('sort')->default(0);
            $table->string('kind', 20)->default('manual');
            $table->boolean('requires_attachment')->default(false);
            $table->integer('due_offset_days')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE order_step_definitions ADD CONSTRAINT order_step_definitions_kind_check CHECK (kind IN ('manual', 'payment', 'receipt', 'booking', 'loading'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_step_definitions');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('lookups');
        Schema::dropIfExists('business_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
