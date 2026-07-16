<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (broadcast, recipient, channel) attempt — the real delivery
     * report (Plan §8.7.5). Deliberately not a mutable JSON counter on
     * `broadcasts.stats`: sends happen via queued jobs, so a shared counter
     * would race; this is append-only and the true source of truth, read
     * live (grouped by channel/status) wherever a count is shown.
     */
    public function up(): void
    {
        Schema::create('broadcast_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('status');
            $table->timestamp('created_at')->nullable();

            $table->index(['broadcast_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_deliveries');
    }
};
