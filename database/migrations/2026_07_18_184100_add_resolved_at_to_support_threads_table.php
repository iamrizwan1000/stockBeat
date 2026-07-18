<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan §8.7.6 SLA dashboard needs "resolution time" (`resolved_at -
 * created_at`). `ResolveThreadAction` sets this going forward; existing
 * already-resolved threads have no historical resolved_at to backfill
 * (never captured), so they stay null and are simply excluded from
 * resolution-time metrics rather than backfilled with a fabricated value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('csat');
        });
    }

    public function down(): void
    {
        Schema::table('support_threads', function (Blueprint $table) {
            $table->dropColumn('resolved_at');
        });
    }
};
