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
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('revenue_base', 12, 2)->nullable();
            $table->decimal('aov', 12, 2)->default(0);
            $table->unsignedInteger('refunds')->default(0);
            $table->timestamps();

            $table->unique(['connection_id', 'date']);
            $table->index(['team_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
