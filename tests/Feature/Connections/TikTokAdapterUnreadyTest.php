<?php

use App\Exceptions\Connections\AdapterNotReadyException;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Connections\Adapters\TikTokAdapter;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The "stub-ready, no live creds" contract this whole build rests on:
 * every gated method throws AdapterNotReadyException when
 * config('services.tiktok_shop.*') is unset — the default test environment,
 * and (crucially) the environment this app actually runs in until a real
 * TikTok Shop Partner Center app exists.
 *
 * Explicitly nulled here rather than assumed from a blank .env — a real
 * TikTok Shop app now exists for this project (Plan §15.2), so the ambient
 * environment genuinely has these set; this file specifically tests the
 * *unconfigured* code path regardless of that.
 */
beforeEach(function () {
    config([
        'services.tiktok_shop.app_key' => null,
        'services.tiktok_shop.app_secret' => null,
    ]);
});
function tiktokOrderForUnreadyTests(): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'credentials' => ['access_token' => 'fake', 'refresh_token' => 'fake-refresh'],
    ]);

    return Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_TIKTOK,
        'external_id' => '1729000000000000000',
        'status' => Order::STATUS_UNFULFILLED,
    ]);
}

test('authorizationUrl throws when app credentials are not configured', function () {
    app(TikTokAdapter::class)->authorizationUrl([], 'state-token');
})->throws(AdapterNotReadyException::class);

test('completeConnection throws when app credentials are not configured', function () {
    app(TikTokAdapter::class)->completeConnection(
        Team::factory()->create(),
        'My TikTok Shop',
        [],
        'nonce',
        Request::create('/hooks/tiktok/oauth/callback', 'GET', ['code' => 'x']),
    );
})->throws(AdapterNotReadyException::class);

test('refreshAuth throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);

    app(TikTokAdapter::class)->refreshAuth($connection);
})->throws(AdapterNotReadyException::class);

test('registerWebhooks throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);

    app(TikTokAdapter::class)->registerWebhooks($connection);
})->throws(AdapterNotReadyException::class);

test('fulfill throws when app credentials are not configured', function () {
    app(TikTokAdapter::class)->fulfill(tiktokOrderForUnreadyTests(), new FulfillmentData('TT12345'));
})->throws(AdapterNotReadyException::class);

test('cancel throws when app credentials are not configured', function () {
    app(TikTokAdapter::class)->cancel(tiktokOrderForUnreadyTests(), 'Out of stock');
})->throws(AdapterNotReadyException::class);

test('fetchOrders throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);

    app(TikTokAdapter::class)->fetchOrders($connection, now()->subDay());
})->throws(AdapterNotReadyException::class);

test('fetchOrderDetail throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);

    app(TikTokAdapter::class)->fetchOrderDetail($connection, '123');
})->throws(AdapterNotReadyException::class);

test('sendMessage always throws regardless of configuration', function () {
    config([
        'services.tiktok_shop.app_key' => 'key',
        'services.tiktok_shop.app_secret' => 'secret',
    ]);

    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);
    $thread = InboxThread::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'channel' => StoreConnection::PLATFORM_TIKTOK,
    ]);

    app(TikTokAdapter::class)->sendMessage($thread, 'Hello');
})->throws(AdapterNotReadyException::class);

test('connect always throws a LogicException — TikTok Shop only connects via OAuth', function () {
    app(TikTokAdapter::class)->connect(new ConnectRequest(Team::factory()->create(), 'x', []));
})->throws(LogicException::class);

test('refund always fails cleanly regardless of configuration — buyer-initiated only', function () {
    $result = app(TikTokAdapter::class)->refund(tiktokOrderForUnreadyTests(), new RefundData(amount: 10.0));

    expect($result->success)->toBeFalse();
});

test('capabilities reflects the plan even though the adapter is unconfigured', function () {
    $capabilities = app(TikTokAdapter::class)->capabilities();

    expect($capabilities->realtimeOrders)->toBeTrue();
    expect($capabilities->fulfillTracking)->toBeTrue();
    expect($capabilities->refunds)->toBeFalse();
    expect($capabilities->cancel)->toBeTrue();
    expect($capabilities->messagingMode)->toBe('none');
    expect($capabilities->inventoryUpdate)->toBeTrue();
    expect($capabilities->reviewsFeedback)->toBeFalse();
});

test('parseWebhook returns null for an unsigned request regardless of configuration', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_TIKTOK]);

    $result = app(TikTokAdapter::class)->parseWebhook($connection, Request::create('/hooks/tiktok/1', 'POST'));

    expect($result)->toBeNull();
});

test('starting a tiktok connection via the API surfaces a clean 422 rather than a 500', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->seed(PlanSeeder::class);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['tiktok']])->assertOk();

    test()->postJson('/api/v1/connections/tiktok/start', [
        'name' => 'My TikTok Shop',
        'credentials' => [],
    ])->assertStatus(422);
});
