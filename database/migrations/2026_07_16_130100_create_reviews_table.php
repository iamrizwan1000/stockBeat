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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('product_title')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('reviewer_name')->nullable();
            $table->text('content')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
