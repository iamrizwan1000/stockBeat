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
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->string('external_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status');
            $table->timestamp('arrival_date')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
