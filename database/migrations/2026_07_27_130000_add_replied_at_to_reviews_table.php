<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a real gap in Review-reply (Plan §4.15): replying was entirely
 * stateless before this — `ReplyToReviewAction` called the adapter and
 * returned, nothing ever recorded that a reply happened. `replied_at`
 * lets the mobile app show "Replied" on a review, and lets the admin
 * dashboard's feature-adoption metrics count real reply usage instead of
 * having no data at all for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('replied_at')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('replied_at');
        });
    }
};
