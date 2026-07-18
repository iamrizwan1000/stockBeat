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
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // RevenueCat's own webhook event-type vocabulary (Plan §6.1), stored
            // verbatim rather than remapped into app-specific labels — e.g.
            // INITIAL_PURCHASE, RENEWAL, PRODUCT_CHANGE, CANCELLATION,
            // UNCANCELLATION, EXPIRATION, BILLING_ISSUE, NON_RENEWING_PURCHASE.
            $table->string('event_type');
            // Transaction amount, if this event carried one (see
            // ProcessRevenueCatEventAction's price-extraction comment for which
            // event types reliably do). Null, never a fabricated guess, when the
            // payload didn't include a price.
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('raw_payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['team_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
