<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A separate cursor from `last_sync_at` (orders) — the eBay member-message
 * poller (`PollEbayMessagesJob`, Plan §4.5/§7.3) runs on its own schedule
 * and shouldn't be coupled to whatever the order poller happens to have
 * touched most recently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->timestamp('last_message_sync_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropColumn('last_message_sync_at');
        });
    }
};
