<?php

use App\Exceptions\Connections\AdapterNotReadyException;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Connections\Adapters\AmazonAdapter;
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
 * config('services.amazon.*') is unset — the default test environment,
 * and (crucially) the environment this app actually runs in until real
 * SP-API credentials exist.
 */
function amazonOrderForUnreadyTests(): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'credentials' => ['access_token' => 'fake', 'refresh_token' => 'fake-refresh', 'selling_partner_id' => 'A1B2C3'],
    ]);

    return Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_AMAZON,
        'external_id' => '111-2223334-5556667',
    ]);
}

test('authorizationUrl throws when app credentials are not configured', function () {
    app(AmazonAdapter::class)->authorizationUrl([], 'state-token');
})->throws(AdapterNotReadyException::class);

test('completeConnection throws when app credentials are not configured', function () {
    app(AmazonAdapter::class)->completeConnection(
        Team::factory()->create(),
        'My Amazon Store',
        [],
        'nonce',
        Request::create('/hooks/amazon/oauth/callback', 'GET', ['spapi_oauth_code' => 'x']),
    );
})->throws(AdapterNotReadyException::class);

test('refreshAuth throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_AMAZON]);

    app(AmazonAdapter::class)->refreshAuth($connection);
})->throws(AdapterNotReadyException::class);

test('fulfill throws when app credentials are not configured', function () {
    app(AmazonAdapter::class)->fulfill(amazonOrderForUnreadyTests(), new FulfillmentData('1Z999'));
})->throws(AdapterNotReadyException::class);

test('refund throws when app credentials are not configured', function () {
    app(AmazonAdapter::class)->refund(amazonOrderForUnreadyTests(), new RefundData(amount: 10.0));
})->throws(AdapterNotReadyException::class);

test('cancel throws when app credentials are not configured', function () {
    app(AmazonAdapter::class)->cancel(amazonOrderForUnreadyTests(), 'Out of stock');
})->throws(AdapterNotReadyException::class);

test('fetchOrders throws when app credentials are not configured', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_AMAZON]);

    app(AmazonAdapter::class)->fetchOrders($connection, now()->subDay());
})->throws(AdapterNotReadyException::class);

test('sendMessage always throws regardless of configuration', function () {
    config([
        'services.amazon.client_id' => 'id',
        'services.amazon.client_secret' => 'secret',
        'services.amazon.aws_access_key_id' => 'key',
        'services.amazon.aws_secret_access_key' => 'secret-key',
        'services.amazon.role_arn' => 'arn:aws:iam::123456789012:role/spapi',
    ]);

    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_AMAZON]);
    $thread = InboxThread::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'channel' => StoreConnection::PLATFORM_AMAZON,
    ]);

    app(AmazonAdapter::class)->sendMessage($thread, 'Hello');
})->throws(AdapterNotReadyException::class);

test('connect always throws a LogicException — Amazon only connects via OAuth', function () {
    app(AmazonAdapter::class)->connect(new ConnectRequest(Team::factory()->create(), 'x', []));
})->throws(LogicException::class);

test('capabilities reflects the §7.8 matrix even though the adapter is unconfigured', function () {
    $capabilities = app(AmazonAdapter::class)->capabilities();

    expect($capabilities->realtimeOrders)->toBeFalse();
    expect($capabilities->fulfillTracking)->toBeTrue();
    expect($capabilities->refunds)->toBeTrue();
    expect($capabilities->cancel)->toBeTrue();
    expect($capabilities->messagingMode)->toBe('template');
});

test('registerWebhooks is a safe no-op regardless of configuration', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_AMAZON]);

    app(AmazonAdapter::class)->registerWebhooks($connection);
})->throwsNoExceptions();

test('parseWebhook always returns null regardless of configuration', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_AMAZON]);

    $result = app(AmazonAdapter::class)->parseWebhook($connection, Request::create('/hooks/amazon', 'POST'));

    expect($result)->toBeNull();
});

test('starting an amazon connection via the API surfaces a clean 422 rather than a 500', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->seed(PlanSeeder::class);

    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['amazon']])->assertOk();

    test()->postJson('/api/v1/connections/amazon/start', [
        'name' => 'My Amazon Store',
        'credentials' => [],
    ])->assertStatus(422);
});
