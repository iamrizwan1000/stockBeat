<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency guard for the 80%-quota push notification (Plan §5.1:
     * "quota consumption shown in Settings with an upsell at 80%") — one row
     * per team per channel per calendar month it was actually sent, same
     * "row exists = already handled" pattern `RevenueCatEvent` uses for
     * webhook idempotency, scoped to calendar month via `created_at` rather
     * than a stored month column (matching `AiUsageLedger`'s calendar-month
     * convention elsewhere).
     */
    public function up(): void
    {
        Schema::create('quota_warning_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', ['sms', 'ai_questions', 'emails']);
            $table->timestamps();

            $table->index(['team_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_warning_notifications');
    }
};
