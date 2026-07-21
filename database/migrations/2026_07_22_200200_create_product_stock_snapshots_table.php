<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('stock_quantity');
            $table->timestamp('recorded_at');

            $table->index(['product_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_snapshots');
    }
};
