<?php

use App\Actions\Admin\Promotions\ComputeCampaignStatsAction;
use App\Models\FxRate;
use App\Models\PromoCampaign;
use App\Models\PromoCampaignRedemption;
use App\Models\Segment;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('offer_code and intro_offer campaigns are not computable', function () {
    $offerCode = PromoCampaign::factory()->create(['type' => PromoCampaign::TYPE_OFFER_CODE]);
    $introOffer = PromoCampaign::factory()->create(['type' => PromoCampaign::TYPE_INTRO_OFFER]);

    $action = app(ComputeCampaignStatsAction::class);

    foreach ([$offerCode, $introOffer] as $campaign) {
        $stats = $action->handle($campaign);

        expect($stats['computable'])->toBeFalse();
        expect($stats['reason'])->not->toBeNull();
        expect($stats['redemptions'])->toBeNull();
        expect($stats['revenue_impact'])->toBeNull();
    }
});

test('a server_comp campaign never applied has zero redemptions and no computable conversion', function () {
    $campaign = PromoCampaign::factory()->serverComp()->create();

    $stats = app(ComputeCampaignStatsAction::class)->handle($campaign);

    expect($stats['computable'])->toBeTrue();
    expect($stats['redemptions'])->toBe(0);
    expect($stats['targeted_segment_size'])->toBeNull();
    expect($stats['conversion'])->toBeNull();
    expect($stats['revenue_impact'])->toBe(0.0);
});

test('redemptions count distinct teams and conversion divides by current targeted segment size', function () {
    $segment = Segment::factory()->create(['filters' => ['plan' => Subscription::STATUS_TRIAL]]);

    $matchingOwner1 = User::factory()->create();
    $matchingTeam1 = Team::factory()->create(['owner_id' => $matchingOwner1->id]);
    Subscription::factory()->create(['team_id' => $matchingTeam1->id, 'status' => Subscription::STATUS_TRIAL]);

    $matchingOwner2 = User::factory()->create();
    $matchingTeam2 = Team::factory()->create(['owner_id' => $matchingOwner2->id]);
    Subscription::factory()->create(['team_id' => $matchingTeam2->id, 'status' => Subscription::STATUS_TRIAL]);

    // A third team matches the segment but never redeemed — brings the
    // targeted size to 3 while only 2 teams are recorded as redeemers.
    $nonRedeemingOwner = User::factory()->create();
    $nonRedeemingTeam = Team::factory()->create(['owner_id' => $nonRedeemingOwner->id]);
    Subscription::factory()->create(['team_id' => $nonRedeemingTeam->id, 'status' => Subscription::STATUS_TRIAL]);

    $campaign = PromoCampaign::factory()->serverComp()->create([
        'stats' => [
            'applications' => [
                ['segment_id' => $segment->id, 'recipients_total' => 2, 'applied_at' => now()->toIso8601String()],
            ],
            'recipients_total_all_time' => 2,
        ],
    ]);

    PromoCampaignRedemption::factory()->create(['promo_campaign_id' => $campaign->id, 'team_id' => $matchingTeam1->id]);
    PromoCampaignRedemption::factory()->create(['promo_campaign_id' => $campaign->id, 'team_id' => $matchingTeam2->id]);

    $stats = app(ComputeCampaignStatsAction::class)->handle($campaign);

    expect($stats['redemptions'])->toBe(2);
    expect($stats['targeted_segment_size'])->toBe(3);
    expect($stats['conversion'])->toBe(round(2 / 3, 4));
});

test('applying to everyone (null segment_id) targets the whole non-suspended user base', function () {
    $owner1 = User::factory()->create();
    $team1 = Team::factory()->create(['owner_id' => $owner1->id]);

    $owner2 = User::factory()->create();
    Team::factory()->create(['owner_id' => $owner2->id]);

    $campaign = PromoCampaign::factory()->serverComp()->create([
        'stats' => [
            'applications' => [
                ['segment_id' => null, 'recipients_total' => 1, 'applied_at' => now()->toIso8601String()],
            ],
            'recipients_total_all_time' => 1,
        ],
    ]);

    PromoCampaignRedemption::factory()->create(['promo_campaign_id' => $campaign->id, 'team_id' => $team1->id]);

    $stats = app(ComputeCampaignStatsAction::class)->handle($campaign);

    expect($stats['redemptions'])->toBe(1);
    expect($stats['targeted_segment_size'])->toBe(2);
});

test('revenue impact only counts subscription events on or after each team redemption, converted to USD', function () {
    $campaign = PromoCampaign::factory()->serverComp()->create();

    $owner = User::factory()->create(['base_currency' => 'AUD']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    $redemption = PromoCampaignRedemption::factory()->create([
        'promo_campaign_id' => $campaign->id,
        'team_id' => $team->id,
        'redeemed_at' => now()->subDays(5),
    ]);

    FxRate::factory()->create(['base' => 'USD', 'quote' => 'AUD', 'rate' => 1.5, 'date' => now()->toDateString()]);

    // Before redemption — excluded entirely from the window.
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'price' => 100.0,
        'currency' => 'AUD',
        'occurred_at' => now()->subDays(10),
    ]);

    // After redemption, priced, convertible.
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'price' => 15.0,
        'currency' => 'AUD',
        'occurred_at' => now(),
    ]);

    // After redemption, no price (e.g. a CANCELLATION).
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'price' => null,
        'currency' => null,
        'occurred_at' => now(),
    ]);

    // After redemption, priced, but no FX rate for this currency.
    SubscriptionEvent::factory()->create([
        'team_id' => $team->id,
        'price' => 20.0,
        'currency' => 'JPY',
        'occurred_at' => now(),
    ]);

    $stats = app(ComputeCampaignStatsAction::class)->handle($campaign);

    expect($stats['revenue_impact'])->toBe(10.0);
    expect($stats['revenue_impact_currency'])->toBe('USD');
    expect($stats['revenue_events_included'])->toBe(1);
    expect($stats['revenue_events_excluded_no_price'])->toBe(1);
    expect($stats['revenue_events_excluded_no_fx_rate'])->toBe(1);

    expect($redemption->team_id)->toBe($team->id);
});
