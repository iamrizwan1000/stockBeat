<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thread needs enough of an address to reply into eBay's Trading API
 * member-messaging call (Plan §4.5/§7.3): the buyer's username and the
 * listing's legacy ItemID. Distinct from `external_thread_id` (there's no
 * single "conversation id" concept on eBay's side — messages are addressed
 * by (ItemID, buyer username) pairs, not a thread id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_threads', function (Blueprint $table) {
            $table->string('external_buyer_username')->nullable()->after('external_thread_id');
            $table->string('external_item_id')->nullable()->after('external_buyer_username');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_threads', function (Blueprint $table) {
            $table->dropColumn(['external_buyer_username', 'external_item_id']);
        });
    }
};
