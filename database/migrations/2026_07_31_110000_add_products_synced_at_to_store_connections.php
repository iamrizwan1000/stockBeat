<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->timestamp('products_synced_at')->nullable()->after('last_sync_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropColumn('products_synced_at');
        });
    }
};
