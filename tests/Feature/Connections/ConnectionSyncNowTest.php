<?php

use App\Jobs\PollEbayOrdersJob;
use App\Jobs\PollShopifyOrdersJob;
use App\Jobs\PollShopifyProductsJob;
use App\Jobs\PollWooOrdersJob;
use App\Jobs\PollWooProductsJob;
use App\Models\StoreConnection;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedUserForSyncNowTest(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('triggering sync now requires authentication', function () {
    $connection = StoreConnection::factory()->create();

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertUnauthorized();
});

test('an owner can trigger sync now and it dispatches the platform-specific poll job', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'platform' => StoreConnection::PLATFORM_WOO,
    ]);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")
        ->assertOk()
        ->assertJsonPath('message', 'Sync started.');

    Queue::assertPushed(PollWooOrdersJob::class, fn (PollWooOrdersJob $job) => $job->connectionId === $connection->id);
});

test('sync now also dispatches the product-catalog poll job for platforms that have one', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $woo = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $shopify = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_SHOPIFY]);

    test()->postJson("/api/v1/connections/{$woo->id}/sync-now")->assertOk();
    test()->postJson("/api/v1/connections/{$shopify->id}/sync-now")->assertOk();

    Queue::assertPushed(PollWooProductsJob::class, fn (PollWooProductsJob $job) => $job->connectionId === $woo->id);
    Queue::assertPushed(PollShopifyProductsJob::class, fn (PollShopifyProductsJob $job) => $job->connectionId === $shopify->id);
});

test('sync now on a platform with no product-catalog poll job only dispatches the orders job', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_EBAY]);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertOk();

    Queue::assertPushed(PollEbayOrdersJob::class, fn (PollEbayOrdersJob $job) => $job->connectionId === $connection->id);
    Queue::assertNotPushed(PollWooProductsJob::class);
    Queue::assertNotPushed(PollShopifyProductsJob::class);
});

test('a shopify connection dispatches the shopify poll job, not woo\'s', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
    ]);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertOk();

    Queue::assertPushed(PollShopifyOrdersJob::class, fn (PollShopifyOrdersJob $job) => $job->connectionId === $connection->id);
    Queue::assertNotPushed(PollWooOrdersJob::class);
});

test('a second sync-now call within the cooldown is rejected with 429', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertOk();

    $response = test()->postJson("/api/v1/connections/{$connection->id}/sync-now");
    $response->assertStatus(429);
    expect($response->json('message'))->toContain('try again in');

    Queue::assertPushed(PollWooOrdersJob::class, 1);
});

test('the cooldown is scoped per connection, not per team', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connectionA = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $connectionB = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_SHOPIFY]);

    test()->postJson("/api/v1/connections/{$connectionA->id}/sync-now")->assertOk();
    test()->postJson("/api/v1/connections/{$connectionB->id}/sync-now")->assertOk();

    Queue::assertPushed(PollWooOrdersJob::class, 1);
    Queue::assertPushed(PollShopifyOrdersJob::class, 1);
});

test('sync now works again once the cooldown expires', function () {
    Queue::fake();
    $user = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id, 'platform' => StoreConnection::PLATFORM_WOO]);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertOk();
    test()->travel(61)->seconds();
    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertOk();

    Queue::assertPushed(PollWooOrdersJob::class, 2);
});

test('a viewer cannot trigger sync now', function () {
    $owner = onboardedUserForSyncNowTest();
    $connection = StoreConnection::factory()->create(['team_id' => $owner->ownedTeam->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create([
        'team_id' => $owner->ownedTeam->id,
        'user_id' => $viewer->id,
        'role' => TeamMember::ROLE_VIEWER,
    ]);
    Sanctum::actingAs($viewer);

    test()->postJson("/api/v1/connections/{$connection->id}/sync-now")->assertForbidden();
});

test('a team cannot sync another team\'s connection', function () {
    onboardedUserForSyncNowTest();
    $otherConnection = StoreConnection::factory()->create();

    test()->postJson("/api/v1/connections/{$otherConnection->id}/sync-now")->assertNotFound();
});
