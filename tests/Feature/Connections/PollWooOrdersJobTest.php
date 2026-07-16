<?php

use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollWooOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\Adapters\Woo\WooOrderMapper;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

/**
 * @param  ?ResponseSequence  $ordersSequence  Controls successive calls to the
 *                                             orders endpoint — the first item is always consumed by connect()'s
 *                                             own credential-validation call.
 */
function pollableWooConnection(?ResponseSequence $ordersSequence = null): StoreConnection
{
    Http::fake([
        '*/wp-json/wc/v3/orders*' => $ordersSequence ?? Http::sequence()->push([], 200),
        '*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 123], 200),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie', 'sells_on' => ['woo']])->assertOk();

    $connectionId = test()->postJson('/api/v1/connections/woo/start', [
        'name' => 'My Woo Store',
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ],
    ])->json('data.connection.id');

    return StoreConnection::query()->find($connectionId);
}

function runPollJob(int $connectionId): void
{
    (new PollWooOrdersJob($connectionId))->handle(app(WooOrderMapper::class), app(IngestOrderAction::class));
}

test('the poller ingests orders returned since the last sync and updates last_sync_at', function () {
    $connection = pollableWooConnection(
        Http::sequence()
            ->push([], 200) // consumed by connect()'s validation call
            ->push([[
                'id' => 900,
                'number' => '900',
                'status' => 'completed',
                'currency' => 'USD',
                'total' => '25.00',
                'billing' => ['first_name' => 'Sam', 'last_name' => 'Buyer', 'email' => 'sam@example.com'],
                'shipping' => [],
                'line_items' => [],
            ]], 200),
    );
    expect($connection->last_sync_at)->toBeNull();

    runPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '900')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('a 401 response marks the connection needs_reauth without throwing', function () {
    $connection = pollableWooConnection(
        Http::sequence()->push([], 200)->pushStatus(401),
    );

    runPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('a transient server error leaves the connection untouched for the next run', function () {
    $connection = pollableWooConnection(
        Http::sequence()->push([], 200)->pushStatus(500),
    );

    runPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connection->fresh()->last_sync_at)->toBeNull();
});

test('polling a non-woo or missing connection is a safe no-op', function () {
    runPollJob(999999);
})->throwsNoExceptions();
