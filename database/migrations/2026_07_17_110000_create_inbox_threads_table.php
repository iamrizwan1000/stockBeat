<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified customer inbox (Plan §4.5/§9) — named `inbox_threads`/
 * `inbox_messages` rather than the bare `threads`/`messages` from §9's
 * literal listing, to stay unambiguous alongside `support_threads`/
 * `support_messages` (the account-support conversation, a separate concept
 * introduced later in the build).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('channel');
            $table->string('external_thread_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_threads');
    }
};
