<?php

use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function fakeWooApiSuccess(): void
{
    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response([], 200),
        '*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 123], 200),
    ]);
}

function onboardedUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    return $user->fresh();
}

function wooCredentials(): array
{
    return [
        'name' => 'My Woo Store',
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_super_secret_key',
            'consumer_secret' => 'cs_super_secret_value',
        ],
    ];
}

test('connecting a store requires authentication', function () {
    test()->postJson('/api/v1/connections/woo/start', wooCredentials())->assertUnauthorized();
});

test('an unknown platform is rejected at the route level', function () {
    $user = onboardedUser();

    test()->postJson('/api/v1/connections/bigcommerce/start', wooCredentials())->assertNotFound();
});

test('connecting without completing profile setup is blocked', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/connections/woo/start', wooCredentials())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Complete profile setup before connecting a store.');
});

test('a woo store connects successfully and credentials are encrypted at rest', function () {
    fakeWooApiSuccess();
    onboardedUser();

    $response = test()->postJson('/api/v1/connections/woo/start', wooCredentials());

    $response->assertCreated()
        ->assertJsonPath('data.connection.platform', 'woo')
        ->assertJsonPath('data.connection.status', 'active');

    $response->assertJsonMissingPath('data.connection.credentials');

    $connectionId = $response->json('data.connection.id');
    $raw = DB::table('store_connections')->where('id', $connectionId)->value('credentials');

    expect($raw)->not->toContain('cs_super_secret_value');

    $connection = StoreConnection::query()->find($connectionId);
    expect($connection->credentials['consumer_secret'])->toBe('cs_super_secret_value');
});

test('invalid woo credentials are rejected before a connection is ever created', function () {
    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response(['code' => 'woocommerce_rest_authentication_error'], 401),
    ]);

    onboardedUser();

    test()->postJson('/api/v1/connections/woo/start', wooCredentials())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credentials');

    expect(StoreConnection::query()->count())->toBe(0);
});

test('connecting a woo store registers real webhooks and stores the secret', function () {
    fakeWooApiSuccess();
    onboardedUser();

    $response = test()->postJson('/api/v1/connections/woo/start', wooCredentials());
    $response->assertCreated()->assertJsonPath('data.connection.webhook_status', 'active');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wp-json/wc/v3/webhooks')
        && $request['topic'] === 'order.created');

    $connection = StoreConnection::query()->find($response->json('data.connection.id'));
    expect($connection->credentials['webhook_secret'])->not->toBeEmpty();
    expect($connection->credentials['webhook_ids'])->toHaveKey('order.created');
});

test('a not-yet-ready platform returns a clear error', function () {
    onboardedUser();

    test()->postJson('/api/v1/connections/shopify/start', [
        'name' => 'My Shopify Store',
        'credentials' => [],
    ])->assertUnprocessable()->assertJsonPath('message', fn ($message) => str_contains($message, 'shopify'));
});

test('a free-plan team is blocked from connecting a second store', function () {
    fakeWooApiSuccess();
    $user = onboardedUser();
    $team = $user->ownedTeam;
    $team->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->postJson('/api/v1/connections/woo/start', wooCredentials())->assertCreated();

    test()->postJson('/api/v1/connections/woo/start', array_merge(wooCredentials(), ['name' => 'Second Store']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('platform');
});

test('a pro-trial team can connect more than one store', function () {
    fakeWooApiSuccess();
    onboardedUser();

    test()->postJson('/api/v1/connections/woo/start', wooCredentials())->assertCreated();
    test()->postJson('/api/v1/connections/woo/start', array_merge(wooCredentials(), ['name' => 'Second Store']))
        ->assertCreated();

    expect(StoreConnection::query()->count())->toBe(2);
});

test('listing connections is scoped to the caller\'s team', function () {
    fakeWooApiSuccess();
    $userA = onboardedUser();
    test()->postJson('/api/v1/connections/woo/start', wooCredentials())->assertCreated();

    $userB = onboardedUser();

    test()->getJson('/api/v1/connections')->assertOk()->assertJsonCount(0, 'data.connections');

    Sanctum::actingAs($userA);
    test()->getJson('/api/v1/connections')->assertOk()->assertJsonCount(1, 'data.connections');
});

test('deleting a connection removes it, and only the owning team can delete it', function () {
    fakeWooApiSuccess();
    $userA = onboardedUser();
    $connectionId = test()->postJson('/api/v1/connections/woo/start', wooCredentials())
        ->json('data.connection.id');

    $userB = onboardedUser();
    test()->deleteJson("/api/v1/connections/{$connectionId}")->assertNotFound();

    Sanctum::actingAs($userA);
    test()->deleteJson("/api/v1/connections/{$connectionId}")->assertOk();

    expect(StoreConnection::query()->find($connectionId))->toBeNull();
});
