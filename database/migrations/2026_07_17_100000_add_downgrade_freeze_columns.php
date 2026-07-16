<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns backing Plan §6.4's "downgrade freezes, never destroys": each one
 * marks a resource as auto-paused/auto-disabled/auto-suspended by the
 * freeze logic (as opposed to a user's own deliberate choice), so
 * re-upgrading only restores what the freeze itself touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->timestamp('auto_disabled_at')->nullable()->after('enabled');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('store_visibility');
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('status');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_reminder_day5_sent_at')->nullable()->after('trial_ends_at');
            $table->timestamp('trial_reminder_day7_sent_at')->nullable()->after('trial_reminder_day5_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('auto_disabled_at');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['trial_reminder_day5_sent_at', 'trial_reminder_day7_sent_at']);
        });
    }
};
