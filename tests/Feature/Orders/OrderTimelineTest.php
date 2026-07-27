<?php

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    Http::fake(['*/wp-json/wc/v3/orders/*' => Http::response(['id' => 1], 200)]);

    // These quick actions push a real notification on success. No real
    // Firebase credential exists in this dev/test environment (a known,
    // documented gap unrelated to the order-timeline feature this file
    // tests) — bind a mock so the container can still construct
    // SendPushNotificationAction without throwing.
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->andReturn([]);
    app()->instance(Messaging::class, $messaging);
});

function onboardedTimelineUser(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['store_url' => 'https://example-shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ]);
    $order = Order::factory()->create([
        'team_id' => $user->currentTeam()->id,
        'connection_id' => $connection->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'external_id' => '1',
    ]);

    return [$user, $order];
}

test('fulfilling an order writes a fulfilled timeline event', function () {
    [, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/fulfill", ['tracking_number' => 'TRACK123', 'carrier' => 'UPS'])->assertOk();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_FULFILLED)->firstOrFail();
    expect($event->payload)->toBe(['tracking_number' => 'TRACK123', 'carrier' => 'UPS']);
});

test('refunding an order writes a refunded timeline event', function () {
    [, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'Damaged'])->assertOk();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_REFUNDED)->firstOrFail();
    expect($event->payload['reason'])->toBe('Damaged');
});

test('cancelling an order writes a cancelled timeline event', function () {
    [, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Out of stock'])->assertOk();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_CANCELLED)->firstOrFail();
    expect($event->payload['reason'])->toBe('Out of stock');
});

test('a failed fulfill (unsupported capability) writes no timeline event', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['etsy']])->assertOk();
    $connection = StoreConnection::factory()->create(['team_id' => $user->fresh()->currentTeam()->id, 'platform' => StoreConnection::PLATFORM_ETSY]);
    $order = Order::factory()->create(['team_id' => $user->fresh()->currentTeam()->id, 'connection_id' => $connection->id, 'platform' => StoreConnection::PLATFORM_ETSY]);

    test()->postJson("/api/v1/orders/{$order->id}/fulfill", ['tracking_number' => 'TRACK123'])->assertStatus(422);

    expect(OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_FULFILLED)->exists())->toBeFalse();
});

test('snoozing an order writes a snoozed timeline event', function () {
    [, $order] = onboardedTimelineUser();
    $until = now()->addDay()->toIso8601String();

    test()->postJson("/api/v1/orders/{$order->id}/snooze", ['until' => $until])->assertOk();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_SNOOZED)->firstOrFail();
    expect($event->payload['until'])->not->toBeNull();
});

test('updating tags writes a tags_updated timeline event', function () {
    [, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/tags", ['tags' => ['gift', 'urgent']])->assertOk();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_TAGS_UPDATED)->firstOrFail();
    expect($event->payload['tags'])->toBe(['gift', 'urgent']);
});

test('adding a note writes a note_added timeline event with the acting user', function () {
    [$user, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/notes", ['body' => 'Called the customer about the delay.'])->assertCreated();

    $event = OrderEvent::query()->where('order_id', $order->id)->where('type', OrderEvent::TYPE_NOTE_ADDED)->firstOrFail();
    expect($event->payload['user_id'])->toBe($user->id);
    expect($event->payload['excerpt'])->toBe('Called the customer about the delay.');
});

test('a bulk cancel writes a timeline event per affected order', function () {
    [$user, $orderA] = onboardedTimelineUser();
    $team = $user->currentTeam();
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['store_url' => 'https://example-shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ]);
    $orderB = Order::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'platform' => StoreConnection::PLATFORM_WOO, 'external_id' => '2']);

    test()->postJson('/api/v1/orders/bulk-cancel', ['ids' => [$orderA->id, $orderB->id]])->assertOk();

    expect(OrderEvent::query()->where('order_id', $orderA->id)->where('type', OrderEvent::TYPE_CANCELLED)->exists())->toBeTrue();
    expect(OrderEvent::query()->where('order_id', $orderB->id)->where('type', OrderEvent::TYPE_CANCELLED)->exists())->toBeTrue();
});

test('GET /orders/{id} returns events in chronological order', function () {
    [, $order] = onboardedTimelineUser();

    test()->postJson("/api/v1/orders/{$order->id}/tags", ['tags' => ['gift']])->assertOk();
    test()->postJson("/api/v1/orders/{$order->id}/notes", ['body' => 'A note.'])->assertCreated();

    $response = test()->getJson("/api/v1/orders/{$order->id}")->assertOk();
    $types = collect($response->json('data.order.events'))->pluck('type');

    // Factory-created orders bypass IngestOrderAction, so there's no
    // "created" event here — just confirming the two real events this test
    // produced come back in the order they actually happened.
    expect($types->all())->toBe([OrderEvent::TYPE_TAGS_UPDATED, OrderEvent::TYPE_NOTE_ADDED]);
});
