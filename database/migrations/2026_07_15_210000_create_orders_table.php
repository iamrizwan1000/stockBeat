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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->enum('platform', ['shopify', 'woo', 'ebay', 'etsy', 'amazon']);
            $table->string('external_id');
            $table->string('order_number');
            $table->string('status');
            $table->string('fulfillment_status');
            $table->string('payment_status');
            $table->string('currency', 3);
            $table->decimal('total', 12, 2);
            $table->decimal('total_base_currency', 12, 2)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->json('shipping_address')->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('ship_by_at')->nullable();
            $table->timestamp('check_at')->nullable();
            $table->json('tags')->nullable();
            $table->json('raw')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
            $table->index('check_at');
            $table->index(['team_id', 'placed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
