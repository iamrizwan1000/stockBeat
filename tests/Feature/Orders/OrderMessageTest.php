<?php

use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    Mail::fake();
});

function onboardedOrderMessenger(): array
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/profile/setup', [
        'name' => 'Jamie Seller',
        'sells_on' => ['woo'],
    ])->assertOk();

    $user = $user->fresh();
    $connection = StoreConnection::factory()->create(['team_id' => $user->ownedTeam->id]);

    return [$user, $connection];
}

test('messaging an order for the first time creates its inbox thread and a message', function () {
    [$user, $connection] = onboardedOrderMessenger();
    $order = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'customer_email' => 'buyer@example.com',
    ]);

    test()->postJson("/api/v1/orders/{$order->id}/message", ['body' => 'Just checking in on your order.'])
        ->assertCreated()
        ->assertJsonPath('data.message.body', 'Just checking in on your order.');

    $thread = InboxThread::query()->where('order_id', $order->id)->firstOrFail();
    expect(InboxMessage::query()->where('thread_id', $thread->id)->count())->toBe(1);
});

test('messaging the same order twice reuses the same thread', function () {
    [$user, $connection] = onboardedOrderMessenger();
    $order = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'customer_email' => 'buyer@example.com',
    ]);

    test()->postJson("/api/v1/orders/{$order->id}/message", ['body' => 'First message'])->assertCreated();
    test()->postJson("/api/v1/orders/{$order->id}/message", ['body' => 'Second message'])->assertCreated();

    expect(InboxThread::query()->where('order_id', $order->id)->count())->toBe(1);

    $thread = InboxThread::query()->where('order_id', $order->id)->firstOrFail();
    expect($thread->messages()->count())->toBe(2);
});

test('messaging an order outside the callers team is not found', function () {
    onboardedOrderMessenger();
    $otherOrder = Order::factory()->create();

    test()->postJson("/api/v1/orders/{$otherOrder->id}/message", ['body' => 'Hi'])->assertNotFound();
});
