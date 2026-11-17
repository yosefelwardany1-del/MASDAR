<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §3.1, §3.2, §3.7: clients, suppliers and shipping companies in one registry, their
 * contacts, the CRM log and tasks, and the product catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('name_local', 200)->nullable();
            $table->boolean('is_client')->default(false)->index();
            $table->boolean('is_supplier')->default(false)->index();
            $table->boolean('is_shipping')->default(false)->index();
            $table->string('shipping_kind', 60)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users');
            $table->string('source', 60)->nullable();
            $table->string('stage', 60)->nullable();
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->string('default_destination', 255)->nullable();
            $table->text('factory_address')->nullable();
            $table->text('products_note')->nullable();
            $table->text('bank_details')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE parties ADD CONSTRAINT parties_kind_check CHECK (is_client OR is_supplier OR is_shipping)');
        DB::statement('ALTER TABLE parties ADD CONSTRAINT parties_deposit_check CHECK (deposit_percent IS NULL OR deposit_percent BETWEEN 0 AND 100)');

        Schema::create('party_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('name', 160);
            $table->string('role', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('wechat', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties');
            $table->foreignId('user_id')->constrained('users');
            $table->string('channel', 60);
            $table->timestampTz('happened_at');
            $table->text('summary');
            $table->text('next_step')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['party_id', 'happened_at']);
        });
        DB::unprepared('CREATE TRIGGER crm_interactions_append_only BEFORE UPDATE OR DELETE ON crm_interactions FOR EACH ROW EXECUTE FUNCTION masdar_append_only()');

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->date('due_on');
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['assigned_to', 'done_at', 'due_on']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 255);
            $table->string('name_local', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 60)->default('pcs');
            $table->decimal('qty_per_carton', 18, 4)->nullable();
            $table->decimal('carton_length_cm', 18, 4)->nullable();
            $table->decimal('carton_width_cm', 18, 4)->nullable();
            $table->decimal('carton_height_cm', 18, 4)->nullable();
            $table->decimal('cbm_per_carton', 18, 4)->nullable();
            $table->decimal('kg_per_carton', 18, 4)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('parties');
            $table->decimal('supplier_price', 18, 4)->nullable();
            $table->char('supplier_currency', 3)->nullable();
            $table->decimal('sale_price', 18, 4)->nullable();
            $table->char('sale_currency', 3)->nullable();
            $table->string('hs_code', 30)->nullable();
            $table->string('photo')->nullable();
            $table->string('photo_disk', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_quantities_check CHECK (
            (qty_per_carton IS NULL OR qty_per_carton > 0) AND (cbm_per_carton IS NULL OR cbm_per_carton >= 0)
            AND (kg_per_carton IS NULL OR kg_per_carton >= 0) AND (supplier_price IS NULL OR supplier_price >= 0)
            AND (sale_price IS NULL OR sale_price >= 0))');
    }

    public function down(): void
    {
        foreach (['products', 'crm_tasks', 'crm_interactions', 'party_contacts', 'parties'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
