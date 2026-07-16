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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('sku')->nullable();
            $table->string('title');
            $table->integer('stock_quantity')->nullable();
            $table->timestamp('low_stock_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
