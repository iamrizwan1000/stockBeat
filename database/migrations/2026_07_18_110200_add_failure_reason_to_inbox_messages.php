<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A failed outbound message (no customer email on file, an Etsy thread
 * hitting `AdapterNotReadyException`, eBay rejecting the Trading API call)
 * needs to surface *why* — `status` alone doesn't say. Plan §4.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });
    }
};
