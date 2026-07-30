<?php

use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedUserWithWooOrder(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    $team = $user->fresh()->currentTeam();
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['store_url' => 'https://example-shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ]);
    $order = Order::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'status' => Order::STATUS_UNFULFILLED,
        'total' => 50.00,
    ]);

    return [$user, $order];
}

test('a refund double-tap issues only one real refund on the platform', function () {
    Http::fake(['*/wp-json/wc/v3/orders/*/refunds*' => Http::response(['id' => 1], 201)]);
    [, $order] = onboardedUserWithWooOrder();

    $first = test()->postJson("/api/v1/orders/{$order->id}/refund", ['amount' => 10]);
    $second = test()->postJson("/api/v1/orders/{$order->id}/refund", ['amount' => 10]);

    $first->assertOk();
    $second->assertOk();
    expect($second->json('message'))->toContain('already been refunded');

    Http::assertSentCount(1);
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
});

test('a fulfill double-tap only calls the platform once, thanks to the status guard', function () {
    Http::fake(['*/wp-json/wc/v3/orders/*' => Http::response(['id' => 1], 200)]);
    [, $order] = onboardedUserWithWooOrder();

    $first = test()->postJson("/api/v1/orders/{$order->id}/fulfill", ['tracking_number' => 'TRACK123']);
    $second = test()->postJson("/api/v1/orders/{$order->id}/fulfill", ['tracking_number' => 'TRACK123']);

    $first->assertOk();
    $second->assertOk();
    expect($second->json('message'))->toContain('already been marked fulfilled');

    Http::assertSentCount(1);
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

test('a cancel double-tap only calls the platform once, thanks to the status guard', function () {
    Http::fake(['*/wp-json/wc/v3/orders/*' => Http::response(['id' => 1], 200)]);
    [, $order] = onboardedUserWithWooOrder();

    $first = test()->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'changed mind']);
    $second = test()->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'changed mind']);

    $first->assertOk();
    $second->assertOk();
    expect($second->json('message'))->toContain('already been cancelled');

    Http::assertSentCount(1);
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

test('a truly concurrent refund race is closed by the idempotency lock, not just the status guard', function () {
    Http::fake(['*/wp-json/wc/v3/orders/*/refunds*' => Http::response(['id' => 1], 201)]);
    [, $order] = onboardedUserWithWooOrder();

    // Deliberately reuse the SAME in-memory $order instance for both calls
    // (not ->fresh()) — this simulates two truly concurrent requests, each
    // with its own route-bound copy of the order loaded *before* either
    // write happened, so both see "not yet refunded." The status guard
    // alone can't catch this (it only helps on a *sequential* retry after
    // the first has already committed) — only the IdempotencyGuard lock
    // can, since it's what actually stops the second call from ever
    // reaching the platform API.
    $action = app(\App\Actions\Orders\RefundOrderAction::class);
    $resultA = $action->handle($order, 10, null);
    $resultB = $action->handle($order, 10, null);

    expect($resultA->success)->toBeTrue();
    expect($resultB->success)->toBeTrue();
    Http::assertSentCount(1);
});
