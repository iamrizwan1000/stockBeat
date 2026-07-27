<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification priority (Plan §4.20, added 2026-07-27) — `critical`|`high`|
 * `normal`, defaults to `normal` so every existing rule (and every new one
 * that doesn't set it) behaves exactly as before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('sound');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
