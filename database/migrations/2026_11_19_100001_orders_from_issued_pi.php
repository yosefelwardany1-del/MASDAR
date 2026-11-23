<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B01: purchase orders are made from a confirmed PI — the sales order, once the client's deposit
 * has arrived. A business that orders from the factory before the deposit switches this on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->boolean('orders_from_issued_pi')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', fn (Blueprint $table) => $table->dropColumn('orders_from_issued_pi'));
    }
};
