<?php

use App\Actions\Orders\UpdateOrderTagsAction;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedBulkActionsUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

test('bulk order action endpoints require authentication', function () {
    test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => [1]])->assertUnauthorized();
    test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [1], 'tag' => 'gift'])->assertUnauthorized();
});

test('orders can be bulk-cancelled in one call, with a real per-order result for each platform', function () {
    Http::fake(['*/wp-json/wc/v3/orders/*' => Http::response(['id' => 1], 200)]);

    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();

    $wooConnection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['store_url' => 'https://example-shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ]);
    $etsyConnection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_ETSY]);

    $wooOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $wooConnection->id, 'platform' => StoreConnection::PLATFORM_WOO, 'external_id' => '1']);
    $etsyOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $etsyConnection->id, 'platform' => StoreConnection::PLATFORM_ETSY]);

    $response = test()->postJson('/api/v1/orders/bulk-cancel', [
        'ids' => [$wooOrder->id, $etsyOrder->id],
        'reason' => 'Batch cleanup',
    ]);

    $response->assertOk()->assertJsonCount(2, 'data.results');

    $results = collect($response->json('data.results'))->keyBy('id');
    expect($results[$wooOrder->id]['success'])->toBeTrue();
    expect($results[$wooOrder->id]['error'])->toBeNull();
    expect($results[$etsyOrder->id]['success'])->toBeFalse();
    expect($results[$etsyOrder->id]['error'])->toBe("This channel doesn't support cancelling orders from here.");

    expect($wooOrder->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    expect($etsyOrder->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('a bulk cancel touching another team\'s order is rejected entirely, nothing cancelled', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $ownOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $otherOrder = Order::factory()->create();

    $response = test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => [$ownOrder->id, $otherOrder->id]]);

    $response->assertUnprocessable()->assertJsonValidationErrors('ids');
    expect($ownOrder->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('bulk cancel requires at least one id', function () {
    onboardedBulkActionsUser();

    test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');
});

test('a viewer role cannot bulk-cancel orders', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => TeamMember::ROLE_VIEWER]);
    Sanctum::actingAs($viewer);

    test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => [$order->id]])->assertForbidden();
});

test('a free-plan team is blocked from bulk-cancel with a clear upgrade message', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => [$order->id]])
        ->assertForbidden()
        ->assertJsonPath('message', 'Bulk order actions require the Starter plan or higher.');

    expect($order->fresh()->status)->toBe(Order::STATUS_NEW);
});

test('orders can be bulk-tagged in one call, appending to each order\'s existing tags rather than replacing them', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $taggedOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'tags' => ['gift']]);
    $untaggedOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $response = test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$taggedOrder->id, $untaggedOrder->id], 'tag' => 'urgent']);

    $response->assertOk()->assertJsonCount(2, 'data.orders');
    expect($taggedOrder->fresh()->tags)->toBe(['gift', 'urgent']);
    expect($untaggedOrder->fresh()->tags)->toBe(['urgent']);
});

test('bulk-tagging with an already-applied tag does not duplicate it', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'tags' => ['urgent']]);

    test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$order->id], 'tag' => 'urgent'])->assertOk();

    expect($order->fresh()->tags)->toBe(['urgent']);
});

test('re-submitting the identical tag list is a no-op — no duplicate timeline entry, no redundant write', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'tags' => ['sale', 'fragile']]);

    $beforeUpdatedAt = $order->updated_at;
    $beforeEventCount = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_TAGS_UPDATED)->count();

    $result = app(UpdateOrderTagsAction::class)->handle($order, ['sale', 'fragile']);

    expect($result->tags)->toBe(['sale', 'fragile']);
    expect($order->fresh()->updated_at->eq($beforeUpdatedAt))->toBeTrue();
    expect(OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_TAGS_UPDATED)->count())
        ->toBe($beforeEventCount);
});

test('submitting a genuinely different tag list still writes normally', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'tags' => ['sale', 'fragile']]);

    $beforeEventCount = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_TAGS_UPDATED)->count();

    $result = app(UpdateOrderTagsAction::class)->handle($order, ['sale', 'fragile', 'new-tag']);

    expect($result->tags)->toBe(['sale', 'fragile', 'new-tag']);
    expect(OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_TAGS_UPDATED)->count())
        ->toBe($beforeEventCount + 1);
});

test('updating tags on a shopify order pushes the change to Shopify, but a woo order never calls out', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();

    $wooConnection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $wooOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $wooConnection->id, 'platform' => StoreConnection::PLATFORM_WOO, 'tags' => []]);

    app(UpdateOrderTagsAction::class)->handle($wooOrder, ['sale']);

    Http::assertNothingSent();

    $shopifyConnection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'credentials' => ['shop_domain' => 'my-test-shop.myshopify.com', 'access_token' => 'shpat_faketoken'],
    ]);
    $shopifyOrder = Order::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $shopifyConnection->id,
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'external_id' => '999888',
        'tags' => [],
    ]);

    Http::fake(['*/orders/999888.json*' => Http::response(['order' => ['tags' => '']], 200)]);

    app(UpdateOrderTagsAction::class)->handle($shopifyOrder, ['sale']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders/999888.json') && $request->method() === 'PUT');
});

test('a bulk tag touching another team\'s order is rejected entirely, nothing tagged', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $ownOrder = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $otherOrder = Order::factory()->create();

    $response = test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$ownOrder->id, $otherOrder->id], 'tag' => 'urgent']);

    $response->assertUnprocessable()->assertJsonValidationErrors('ids');
    expect($ownOrder->fresh()->tags)->toBeNull();
});

test('bulk tag requires a tag', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$order->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tag');
});

test('a viewer role cannot bulk-tag orders', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);

    $viewer = User::factory()->create();
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $viewer->id, 'role' => TeamMember::ROLE_VIEWER]);
    Sanctum::actingAs($viewer);

    test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$order->id], 'tag' => 'urgent'])->assertForbidden();
});

test('a free-plan team is blocked from bulk-tag with a clear upgrade message', function () {
    $user = onboardedBulkActionsUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id]);
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->postJson('/api/v1/orders/bulk-tag', ['ids' => [$order->id], 'tag' => 'urgent'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Bulk order actions require the Starter plan or higher.');

    expect($order->fresh()->tags)->toBeNull();
});
