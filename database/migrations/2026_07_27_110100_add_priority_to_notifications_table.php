<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the rule's `priority` (Plan §4.20) onto the notification-center
 * row itself, so the client can render/filter by it without joining back to
 * the rule that fired it (which may since have been edited or deleted).
 * Defaults to `normal` for every existing row and every notification type
 * that has no rule behind it at all (trial reminders, support replies, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
