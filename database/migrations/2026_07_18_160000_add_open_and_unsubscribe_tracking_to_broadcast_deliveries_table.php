<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes the "no opened/read tracking" gap (Plan §8.7.5 audit): a real
     * `opened_at` timestamp per delivery, set either by the email
     * tracking-pixel hit or (for push/banner) by the recipient marking the
     * linked in-app `Notification` as read — see `notification_id` below.
     * `unsubscribed_at` records when *this specific* delivery's one-click
     * unsubscribe link was used, distinct from the user-level preference
     * flip it also triggers (kept here too as a per-send audit trail).
     */
    public function up(): void
    {
        Schema::table('broadcast_deliveries', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('status');
            $table->timestamp('unsubscribed_at')->nullable()->after('opened_at');
            $table->foreignId('notification_id')->nullable()->after('unsubscribed_at')->constrained('notifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notification_id');
            $table->dropColumn(['opened_at', 'unsubscribed_at']);
        });
    }
};
