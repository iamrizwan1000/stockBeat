<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Plaintext and separate from the encrypted `shipping_address`
            // blob on purpose (Plan §8.7.2's Customers "country" filter) —
            // a country alone isn't sensitive PII the way a full address
            // is, and an encrypted column can't be queried/filtered in SQL
            // at all, so this is the only way that filter can exist.
            // Not fixed-length: WooCommerce sends a 2-letter ISO code, but
            // nothing guarantees every future platform will.
            $table->string('shipping_country')->nullable()->after('shipping_address');
            $table->index('shipping_country');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_country');
        });
    }
};
