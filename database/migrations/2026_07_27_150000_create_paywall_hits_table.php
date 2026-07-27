<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paywall_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('limit_key');
            $table->string('plan_key')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['limit_key', 'occurred_at']);
            $table->index(['team_id', 'limit_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paywall_hits');
    }
};
