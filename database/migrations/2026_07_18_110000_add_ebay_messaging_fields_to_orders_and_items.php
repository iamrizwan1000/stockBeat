<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures the two eBay-specific identifiers the Trading API's legacy
 * member-messaging call needs (Plan §4.5/§7.3): the buyer's eBay username
 * (message recipient) and the listing's legacy `ItemID` (the REST Sell
 * Fulfillment API's `lineItems[].legacyItemId` bridges the modern order data
 * to the old XML API's id space — see `EbayOrderMapper`). Nullable and
 * unused by every other platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_username')->nullable()->after('customer_email');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('legacy_item_id')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('buyer_username');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('legacy_item_id');
        });
    }
};
