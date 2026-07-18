<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds TikTok Shop (Plan §7.6) to the `platform` enum on both
 * `store_connections` and `orders` — both columns were declared as a fixed
 * MySQL/MariaDB `ENUM` at creation time (not a plain string + application
 * validation), so a new platform needs a real column change here rather
 * than a model/validation-only change. Uses `Schema::table`'s native
 * `change()` (Laravel 11+, no `doctrine/dbal` needed) rather than a raw
 * `ALTER TABLE ... MODIFY` statement, same reasoning already documented on
 * `2026_07_18_100000_encrypt_orders_shipping_address.php` — this works on
 * both MariaDB and the SQLite test DB, where a raw MySQL-only `MODIFY`
 * statement would fail outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon', 'tiktok'])->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon', 'tiktok'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon'])->change();
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon'])->change();
        });
    }
};
