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
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon']);
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_sync_at')->nullable();
            $table->string('webhook_status')->nullable();
            $table->string('region')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_connections');
    }
};
