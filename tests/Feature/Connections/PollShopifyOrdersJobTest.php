<?php

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Actions\Orders\IngestOrderAction;
use App\Jobs\PollShopifyOrdersJob;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A first sync (last_sync_at null) resolves the team's history_days
    // plan limit for its backfill window — needs the plans table seeded,
    // same as any other test touching ResolveEntitlementsAction.
    $this->seed(PlanSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function shopifyConnectionForPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_SHOPIFY,
        'status' => StoreConnection::STATUS_ACTIVE,
        // Recent by default so ordinary tests exercise ongoing-
        // reconciliation behavior, not the first-sync backfill path —
        // tests for backfill/pagination set this to null explicitly.
        'last_sync_at' => now()->subHour(),
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'shpat_faketoken',
            // A real post-`expiring=1` connection always has this — kept
            // in the future here so tests exercise the actual polling
            // logic, not the expiry gate. Tests for the expiry gate itself
            // override this explicitly.
            'expires_at' => now()->addDay()->toIso8601String(),
        ],
    ], $overrides));
}

function runShopifyPollJob(int $connectionId): void
{
    (new PollShopifyOrdersJob($connectionId))->handle(
        app(ShopifyOrderMapper::class),
        app(IngestOrderAction::class),
        app(ShopifyAdapter::class),
        app(ResolveEntitlementsAction::class),
    );
}

test('the poller ingests orders returned since the last sync and updates last_sync_at', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake([
        '*/orders.json*' => Http::response(['orders' => [[
            'id' => 900,
            'name' => '#900',
            'financial_status' => 'paid',
            'fulfillment_status' => null,
            'currency' => 'USD',
            'total_price' => '25.00',
            'customer' => ['first_name' => 'Sam', 'last_name' => 'Buyer', 'email' => 'sam@example.com'],
            'shipping_address' => [],
            'created_at' => '2026-07-16T10:00:00-00:00',
            'tags' => '',
            'line_items' => [],
        ]]], 200),
    ]);

    runShopifyPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '900')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('a 401 response marks the connection needs_reauth without throwing', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake(['*/orders.json*' => Http::response([], 401)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('a transient server error leaves the connection untouched for the next run', function () {
    $originalLastSyncAt = now()->subHour()->startOfSecond();
    $connection = shopifyConnectionForPolling(['last_sync_at' => $originalLastSyncAt]);

    Http::fake(['*/orders.json*' => Http::response([], 500)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
    expect($connection->fresh()->last_sync_at->eq($originalLastSyncAt))->toBeTrue();
});

test('polling a non-shopify or missing connection is a safe no-op', function () {
    runShopifyPollJob(999999);
})->throwsNoExceptions();

test('a 403 for a deprecated non-expiring access token marks the connection needs_reauth', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake(['*/orders.json*' => Http::response([
        'errors' => '[API] Non-expiring access tokens are no longer accepted for the Admin API. Start using expiring offline tokens: https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/offline-access-tokens#expiring-vs-non-expiring-offline-tokens',
    ], 403)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});

test('a 403 for an unrelated reason does not trigger reauth', function () {
    $connection = shopifyConnectionForPolling();

    Http::fake(['*/orders.json*' => Http::response(['errors' => 'This action requires merchant approval for scope: read_orders'], 403)]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('an expired token with a refresh token is refreshed silently before polling', function () {
    $connection = shopifyConnectionForPolling([
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'old-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        '*/orders.json*' => Http::response(['orders' => []], 200),
    ]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->credentials['access_token'])->toBe('refreshed-token');
    expect($connection->fresh()->credentials['refresh_token'])->toBe('new-refresh-token');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/orders.json')
        && $request->hasHeader('X-Shopify-Access-Token', 'refreshed-token'));
});

test('an expired token with no refresh token goes straight to needs_reauth without an API call', function () {
    // Exactly the shape of a connection made before expiring tokens
    // shipped: no refresh_token was ever issued, so there's nothing to
    // refresh with — this is the real production scenario that surfaced
    // the whole issue (Shopify's Admin API rejecting a non-expiring token
    // outright, `expires_at` never having been tracked at all).
    $connection = shopifyConnectionForPolling([
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'shpat_faketoken',
        ],
    ]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
    Http::assertNothingSent();
});

test('a failed refresh attempt marks the connection needs_reauth', function () {
    $connection = shopifyConnectionForPolling([
        'credentials' => [
            'shop_domain' => 'my-test-shop.myshopify.com',
            'access_token' => 'old-token',
            'refresh_token' => 'stale-refresh-token',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    Http::fake([
        'my-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['errors' => 'invalid_grant'], 400),
    ]);

    runShopifyPollJob($connection->id);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders.json'));
});

test('a first sync backfills using the teams history_days plan limit, not just 24 hours', function () {
    $connection = shopifyConnectionForPolling(['last_sync_at' => null]);
    Subscription::factory()->create(['team_id' => $connection->team_id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => 'starter']);

    Http::fake(['*/orders.json*' => Http::response(['orders' => []], 200)]);

    runShopifyPollJob($connection->id);

    Http::assertSent(function ($request) {
        $updatedAtMin = Carbon::parse($request['updated_at_min']);

        // Starter's history_days is 30 — allow a few seconds of slack for
        // test execution time rather than asserting an exact instant.
        return $updatedAtMin->diffInDays(now()->subDays(30)) < 1;
    });
});

test('a first sync on an unlimited-history plan falls back to the default backfill window, not literally unlimited', function () {
    $connection = shopifyConnectionForPolling(['last_sync_at' => null]);
    Subscription::factory()->create(['team_id' => $connection->team_id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => 'premium']);

    Http::fake(['*/orders.json*' => Http::response(['orders' => []], 200)]);

    runShopifyPollJob($connection->id);

    Http::assertSent(function ($request) {
        $updatedAtMin = Carbon::parse($request['updated_at_min']);

        return $updatedAtMin->diffInDays(now()->subDays(90)) < 1;
    });
});

test('an ongoing reconciliation poll (last_sync_at already set) ignores the plan history limit', function () {
    $lastSync = now()->subHours(3)->startOfSecond();
    $connection = shopifyConnectionForPolling(['last_sync_at' => $lastSync]);
    Subscription::factory()->create(['team_id' => $connection->team_id, 'status' => Subscription::STATUS_ACTIVE, 'plan_key' => 'free']);

    Http::fake(['*/orders.json*' => Http::response(['orders' => []], 200)]);

    runShopifyPollJob($connection->id);

    Http::assertSent(fn ($request) => Carbon::parse($request['updated_at_min'])->eq($lastSync));
});

test('pagination follows the Link header across multiple pages and ingests every page', function () {
    $connection = shopifyConnectionForPolling();
    $nextUrl = 'https://my-test-shop.myshopify.com/admin/api/2026-07/orders.json?page_info=abc123&limit=100';

    Http::fake([
        $nextUrl => Http::response(['orders' => [[
            'id' => 902, 'name' => '#902', 'financial_status' => 'paid', 'fulfillment_status' => null,
            'currency' => 'USD', 'total_price' => '10.00', 'customer' => [], 'shipping_address' => [],
            'created_at' => '2026-07-16T10:00:00-00:00', 'tags' => '', 'line_items' => [],
        ]]], 200),
        '*/orders.json*' => Http::response(
            ['orders' => [[
                'id' => 901, 'name' => '#901', 'financial_status' => 'paid', 'fulfillment_status' => null,
                'currency' => 'USD', 'total_price' => '15.00', 'customer' => [], 'shipping_address' => [],
                'created_at' => '2026-07-16T10:00:00-00:00', 'tags' => '', 'line_items' => [],
            ]]],
            200,
            ['Link' => "<{$nextUrl}>; rel=\"next\""],
        ),
    ]);

    runShopifyPollJob($connection->id);

    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '901')->exists())->toBeTrue();
    expect(Order::query()->where('connection_id', $connection->id)->where('external_id', '902')->exists())->toBeTrue();
    expect($connection->fresh()->last_sync_at)->not->toBeNull();
});
