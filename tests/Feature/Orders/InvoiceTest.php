<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

function onboardedSellerWithPricedOrder(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);
    $order = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'total' => 108.00,
        'discount_amount' => 10.00,
        'tax' => 8.00,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'price' => 55, 'qty' => 2]);

    return [$user, $order];
}

test('the invoice endpoint requires authentication', function () {
    $order = Order::factory()->create();

    test()->getJson("/api/v1/orders/{$order->id}/invoice")->assertUnauthorized();
});

test('a team member can download their own order\'s invoice as a PDF', function () {
    [, $order] = onboardedSellerWithPricedOrder();

    $response = test()->get("/api/v1/orders/{$order->id}/invoice");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('an invoice is available on the free plan, unlike bulk order actions', function () {
    [$user, $order] = onboardedSellerWithPricedOrder();
    $user->ownedTeam->subscription->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);

    test()->get("/api/v1/orders/{$order->id}/invoice")->assertOk();
});

test('an invoice for another team\'s order is not found', function () {
    [, $order] = onboardedSellerWithPricedOrder();

    $other = User::factory()->create();
    Sanctum::actingAs($other);
    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Other Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    test()->get("/api/v1/orders/{$order->id}/invoice")->assertNotFound();
});
