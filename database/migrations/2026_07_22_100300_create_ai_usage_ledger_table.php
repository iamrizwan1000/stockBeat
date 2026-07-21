<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->enum('reason', ['monthly_grant', 'topup_iap', 'question', 'freeze']);
            $table->integer('balance_after');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
    }
};
