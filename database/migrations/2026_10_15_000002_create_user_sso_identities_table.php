<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google addresses sign in as which account. One person may hold two mailboxes and
 * both must land on the SAME account. Keyed on the lowercased e-mail, unique across all
 * users: one address is one person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sso_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email', 190)->unique();
            // Google's stable account id (the `sub` claim), recorded on first sign-in.
            $table->string('google_sub', 64)->nullable()->index();
            $table->string('provider', 16)->default('google');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sso_identities');
    }
};
