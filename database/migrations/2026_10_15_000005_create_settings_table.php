<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's settings: ONE row, enforced by the database (id is always 1). Document
 * letterheads and registration numbers (spec §11.3) join it when documents are built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Al Quds');
            $table->string('company_name_ar')->default('القدس');
            $table->string('logo')->nullable();
            $table->string('logo_disk', 30)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_single_row CHECK (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
