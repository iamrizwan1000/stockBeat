<?php

use App\Actions\Inbox\GetOrCreateInboxThreadAction;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inboxOrder(array $overrides = []): Order
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);

    return Order::factory()->create(array_merge([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'customer_name' => 'Jamie Buyer',
        'customer_email' => 'buyer@example.com',
    ], $overrides));
}

test('a new order gets a new thread carrying its channel and customer details', function () {
    $order = inboxOrder();

    $thread = app(GetOrCreateInboxThreadAction::class)->handle($order);

    expect($thread->order_id)->toBe($order->id);
    expect($thread->team_id)->toBe($order->team_id);
    expect($thread->connection_id)->toBe($order->connection_id);
    expect($thread->channel)->toBe($order->platform);
    expect($thread->customer_name)->toBe('Jamie Buyer');
    expect($thread->customer_email)->toBe('buyer@example.com');
});

test('a repeat call for the same order resumes the same thread instead of creating a new one', function () {
    $order = inboxOrder();

    $first = app(GetOrCreateInboxThreadAction::class)->handle($order);
    $second = app(GetOrCreateInboxThreadAction::class)->handle($order);

    expect($second->id)->toBe($first->id);
    expect(InboxThread::query()->where('order_id', $order->id)->count())->toBe(1);
});
