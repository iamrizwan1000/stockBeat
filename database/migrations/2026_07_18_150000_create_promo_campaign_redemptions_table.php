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
        Schema::create('promo_campaign_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // When this team was granted the campaign's comp — the cutoff used to
            // attribute later `subscription_events` revenue to this redemption
            // (ComputeCampaignStatsAction). Reapplying the same campaign to a team
            // that already redeemed it updates this to the latest application
            // rather than inserting a second row — see the unique index below.
            $table->timestamp('redeemed_at');
            // Deliberately left unpopulated for now: there's no reliable way to
            // pick *which* later RevenueCat event a comp caused (a team may have
            // several events after redemption, or none). Revenue impact is
            // computed by windowing `subscription_events` on `redeemed_at`
            // instead of a hard link. The column stays for a future pass that
            // can attribute a specific event with more confidence.
            $table->foreignId('subscription_event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // A team can only be counted once as a "redeemer" of a given
            // campaign, no matter how many times it's re-targeted.
            $table->unique(['promo_campaign_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_campaign_redemptions');
    }
};
