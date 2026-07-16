<?php

use App\Models\AdminUser;
use App\Models\Segment;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a segment can be created, updated, and deleted', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/segments', ['name' => 'Trial ending soon', 'filters' => ['trial_ending_within_days' => 3]])
        ->assertRedirect();

    $segment = Segment::query()->where('name', 'Trial ending soon')->firstOrFail();
    expect($segment->filters)->toBe(['trial_ending_within_days' => 3]);

    test()->actingAs($admin, 'admin')
        ->put("/admin/segments/{$segment->id}", ['name' => 'Trial ending very soon', 'filters' => ['trial_ending_within_days' => 1]])
        ->assertRedirect();

    expect($segment->fresh()->name)->toBe('Trial ending very soon');

    test()->actingAs($admin, 'admin')
        ->delete("/admin/segments/{$segment->id}")
        ->assertRedirect();

    expect(Segment::query()->find($segment->id))->toBeNull();
});

test('a readonly admin cannot create a segment', function () {
    $admin = AdminUser::factory()->readonly()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/segments', ['name' => 'Anything', 'filters' => null])
        ->assertForbidden();
});

test('preview count matches only users satisfying every filter', function () {
    $admin = AdminUser::factory()->create();

    $matching = User::factory()->create(['marketing_opt_in' => true]);
    $matchingTeam = Team::factory()->create(['owner_id' => $matching->id]);
    StoreConnection::factory()->create(['team_id' => $matchingTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);

    $wrongPlatform = User::factory()->create(['marketing_opt_in' => true]);
    $wrongPlatformTeam = Team::factory()->create(['owner_id' => $wrongPlatform->id]);
    StoreConnection::factory()->create(['team_id' => $wrongPlatformTeam->id, 'platform' => StoreConnection::PLATFORM_SHOPIFY]);

    $optedOut = User::factory()->create(['marketing_opt_in' => false]);
    $optedOutTeam = Team::factory()->create(['owner_id' => $optedOut->id]);
    StoreConnection::factory()->create(['team_id' => $optedOutTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);

    $response = test()->actingAs($admin, 'admin')
        ->postJson('/admin/segments/preview-count', [
            'filters' => ['platform' => StoreConnection::PLATFORM_WOO, 'marketing_opt_in' => true],
        ]);

    $response->assertOk()->assertJson(['count' => 1]);
});

test('the trial_ending_within_days filter only matches teams currently on an unexpired trial', function () {
    $admin = AdminUser::factory()->create();

    $endingSoon = User::factory()->create();
    $endingSoonTeam = Team::factory()->create(['owner_id' => $endingSoon->id]);
    Subscription::factory()->create([
        'team_id' => $endingSoonTeam->id,
        'status' => Subscription::STATUS_TRIAL,
        'trial_ends_at' => now()->addDay(),
    ]);

    $endingLater = User::factory()->create();
    $endingLaterTeam = Team::factory()->create(['owner_id' => $endingLater->id]);
    Subscription::factory()->create([
        'team_id' => $endingLaterTeam->id,
        'status' => Subscription::STATUS_TRIAL,
        'trial_ends_at' => now()->addDays(30),
    ]);

    $response = test()->actingAs($admin, 'admin')
        ->postJson('/admin/segments/preview-count', ['filters' => ['trial_ending_within_days' => 3]]);

    $response->assertOk()->assertJson(['count' => 1]);
});
