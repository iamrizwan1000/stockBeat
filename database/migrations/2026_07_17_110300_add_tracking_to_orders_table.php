<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `FulfillOrderAction` passed tracking info straight through to the channel
 * adapter but never persisted it locally — a real gap surfaced by needing
 * a `{tracking}` reply-template variable (Plan §4.5) to have something to
 * substitute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('ship_by_at');
            $table->string('carrier')->nullable()->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tracking_number', 'carrier']);
        });
    }
};
