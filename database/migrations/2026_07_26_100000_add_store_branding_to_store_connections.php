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
            $table->string('store_contact_email')->nullable()->after('name');
            $table->string('store_display_name')->nullable()->after('store_contact_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropColumn(['store_contact_email', 'store_display_name']);
        });
    }
};
