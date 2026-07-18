<?php

use App\Models\AdminUser;
use App\Models\PromoCampaign;
use App\Models\PromoCampaignRedemption;
use App\Models\Segment;
use App\Models\SmsLedger;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('the promotions page requires admin authentication', function () {
    test()->get('/admin/promotions')->assertRedirect('/admin/login');
});

test('the promotions page lists campaigns and segments', function () {
    $admin = AdminUser::factory()->create();
    $campaign = PromoCampaign::factory()->create(['name' => 'Launch 50']);
    $segment = Segment::factory()->create(['name' => 'Trial ending soon']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/promotions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/promotions/index')
            ->has('campaigns', 1)
            ->where('campaigns.0.id', $campaign->id)
            ->has('segments', 1)
            ->where('segments.0.id', $segment->id)
        );
});

test('a campaign can be created, updated, and deleted', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/promotions', [
            'name' => 'Launch 50',
            'type' => PromoCampaign::TYPE_OFFER_CODE,
            'store_ref' => PromoCampaign::STORE_APPLE,
            'config' => ['code_prefix' => 'LAUNCH50', 'discount_pct' => 50, 'duration_months' => 3],
        ])
        ->assertRedirect();

    $campaign = PromoCampaign::query()->where('name', 'Launch 50')->firstOrFail();
    expect($campaign->type)->toBe(PromoCampaign::TYPE_OFFER_CODE);
    expect($campaign->config)->toBe(['code_prefix' => 'LAUNCH50', 'discount_pct' => 50, 'duration_months' => 3]);
    expect($campaign->created_by)->toBe($admin->id);

    test()->actingAs($admin, 'admin')
        ->put("/admin/promotions/{$campaign->id}", [
            'name' => 'Launch 60',
            'type' => PromoCampaign::TYPE_OFFER_CODE,
            'config' => ['discount_pct' => 60],
        ])
        ->assertRedirect();

    expect($campaign->fresh()->name)->toBe('Launch 60');

    test()->actingAs($admin, 'admin')
        ->delete("/admin/promotions/{$campaign->id}")
        ->assertRedirect();

    expect(PromoCampaign::query()->find($campaign->id))->toBeNull();
});

test('a readonly admin cannot create a campaign', function () {
    $admin = AdminUser::factory()->readonly()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/promotions', ['name' => 'Anything', 'type' => PromoCampaign::TYPE_OFFER_CODE])
        ->assertForbidden();
});

test('applying a server_comp campaign to a segment grants pro days to every matching team', function () {
    $admin = AdminUser::factory()->create();

    $matching = User::factory()->create();
    $matchingTeam = Team::factory()->create(['owner_id' => $matching->id]);
    Subscription::factory()->create(['team_id' => $matchingTeam->id, 'status' => Subscription::STATUS_TRIAL]);

    $nonMatching = User::factory()->create();
    Team::factory()->create(['owner_id' => $nonMatching->id]);

    $segment = Segment::factory()->create(['filters' => ['plan' => Subscription::STATUS_TRIAL]]);

    $campaign = PromoCampaign::factory()->serverComp('pro_days', 30)->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => $segment->id])
        ->assertRedirect();

    expect($matchingTeam->subscription()->first()->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($matchingTeam->subscription()->first()->provider)->toBe('comp');

    $campaign = $campaign->fresh();
    expect($campaign->stats['recipients_total_all_time'])->toBe(1);
    expect($campaign->stats['applications'][0]['segment_id'])->toBe($segment->id);

    $redemption = PromoCampaignRedemption::query()
        ->where('promo_campaign_id', $campaign->id)
        ->where('team_id', $matchingTeam->id)
        ->firstOrFail();
    expect($redemption->redeemed_at)->not->toBeNull();
});

test('applying a campaign to a team that already redeemed it updates redeemed_at instead of duplicating', function () {
    // Applied to "everyone" (superadmin, no segment) rather than a status-
    // based segment — granting the comp itself would flip the team's
    // subscription from trial to active, which would make it fall out of a
    // "plan = trial" segment on the second application and never re-redeem.
    $admin = AdminUser::factory()->superadmin()->create();

    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL]);

    $campaign = PromoCampaign::factory()->serverComp('pro_days', 30)->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertRedirect();

    $firstRedeemedAt = PromoCampaignRedemption::query()
        ->where('promo_campaign_id', $campaign->id)
        ->where('team_id', $team->id)
        ->value('redeemed_at');

    Carbon::setTestNow(now()->addHour());

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertRedirect();

    expect(
        PromoCampaignRedemption::query()
            ->where('promo_campaign_id', $campaign->id)
            ->where('team_id', $team->id)
            ->count()
    )->toBe(1);

    $secondRedeemedAt = PromoCampaignRedemption::query()
        ->where('promo_campaign_id', $campaign->id)
        ->where('team_id', $team->id)
        ->value('redeemed_at');

    expect($secondRedeemedAt)->not->toEqual($firstRedeemedAt);

    Carbon::setTestNow();
});

test('the campaign show page includes real computed stats', function () {
    $admin = AdminUser::factory()->create();

    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);

    $campaign = PromoCampaign::factory()->serverComp('pro_days', 30)->create();
    PromoCampaignRedemption::factory()->create([
        'promo_campaign_id' => $campaign->id,
        'team_id' => $team->id,
    ]);

    test()->actingAs($admin, 'admin')
        ->get("/admin/promotions/{$campaign->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/promotions/show')
            ->where('campaign.id', $campaign->id)
            ->where('computed_stats.computable', true)
            ->where('computed_stats.redemptions', 1)
        );
});

test('the campaign show page for an offer_code campaign reports stats as not computable', function () {
    $admin = AdminUser::factory()->create();
    $campaign = PromoCampaign::factory()->create(['type' => PromoCampaign::TYPE_OFFER_CODE]);

    test()->actingAs($admin, 'admin')
        ->get("/admin/promotions/{$campaign->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/promotions/show')
            ->where('computed_stats.computable', false)
        );
});

test('applying a server_comp campaign grants bonus sms credits when configured for that', function () {
    $admin = AdminUser::factory()->superadmin()->create();

    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);

    $campaign = PromoCampaign::factory()->serverComp('sms_credits', 100)->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertRedirect();

    expect(SmsLedger::query()->where('team_id', $team->id)->sum('delta'))->toBe(100);
});

test('a non-superadmin cannot apply a comp to everyone', function () {
    $admin = AdminUser::factory()->create();
    $campaign = PromoCampaign::factory()->serverComp()->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertSessionHasErrors('campaign');
});

test('a superadmin can apply a comp to everyone', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $user = User::factory()->create();
    Team::factory()->create(['owner_id' => $user->id]);

    $campaign = PromoCampaign::factory()->serverComp('pro_days', 15)->create();

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

test('applying an offer_code campaign is rejected', function () {
    $admin = AdminUser::factory()->create();
    $campaign = PromoCampaign::factory()->create(['type' => PromoCampaign::TYPE_OFFER_CODE]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertSessionHasErrors('campaign');
});

test('applying a server_comp campaign with no comp_type configured is rejected', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $campaign = PromoCampaign::factory()->create(['type' => PromoCampaign::TYPE_SERVER_COMP, 'config' => []]);

    test()->actingAs($admin, 'admin')
        ->post("/admin/promotions/{$campaign->id}/apply", ['segment_id' => null])
        ->assertSessionHasErrors('campaign');
});
