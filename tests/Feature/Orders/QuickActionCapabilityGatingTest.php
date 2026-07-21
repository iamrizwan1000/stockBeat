<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/**
 * Confirms the real HTTP status for an unsupported-capability quick action
 * is 422 (via ValidationException), not 200 with success:false — the
 * OrderController's own auto-generated @response annotations claim 200,
 * which is stale/wrong. Documented for mobile in
 * docs/mobile/orders-api-reference.md; this test is what verified it.
 */
function onboardedUserWithPlatformOrder(string $platform): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => [$platform]])->assertOk();

    $team = $user->fresh()->currentTeam();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => $platform]);
    $order = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'platform' => $platform]);

    return [$user, $order];
}

test('cancelling an order on a platform without cancel support returns a clean 422', function () {
    [, $order] = onboardedUserWithPlatformOrder(StoreConnection::PLATFORM_ETSY);

    $response = test()->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'test']);

    $response->assertStatus(422);
    expect($response->json('errors.order.0'))->toBe("This channel doesn't support cancelling orders from here.");
});

test('refunding an order on a platform without refund support returns a clean 422', function () {
    [, $order] = onboardedUserWithPlatformOrder(StoreConnection::PLATFORM_TIKTOK);

    $response = test()->postJson("/api/v1/orders/{$order->id}/refund", ['amount' => 10]);

    $response->assertStatus(422);
    expect($response->json('errors.order.0'))->toBe("This channel doesn't support refunds from here.");
});

test('order detail exposes discount_amount and tax when present', function () {
    [, $order] = onboardedUserWithPlatformOrder(StoreConnection::PLATFORM_WOO);
    $order->update(['discount_amount' => 5.00, 'tax' => 4.50]);

    $response = test()->getJson("/api/v1/orders/{$order->id}");

    $response->assertOk();
    expect($response->json('data.order.discount_amount'))->toBe(5);
    expect($response->json('data.order.tax'))->toBe(4.5);
});
