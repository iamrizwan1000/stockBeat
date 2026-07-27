<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favorite/saved order filters (Plan §4.23, added 2026-07-27) — a named,
 * team-shared preset over the exact same filter params `ListOrdersAction`
 * already accepts. No filtering logic changes; this table only persists
 * what the caller would otherwise have to re-enter every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_order_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_order_filters');
    }
};
