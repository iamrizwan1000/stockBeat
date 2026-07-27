<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A minimal, generic "this feature was used" log for the admin dashboard's
 * feature-adoption metrics — deliberately separate from `order_events`
 * (that table is a per-order history of real state changes; this one is
 * cross-team usage counting for stateless actions like PDF generation that
 * have no other persisted record at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->timestamp('occurred_at');
            $table->index(['feature', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usage_events');
    }
};
