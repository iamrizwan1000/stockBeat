<?php

use App\Actions\Inbox\RenderReplyTemplateAction;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it substitutes customer name, order number, and tracking from the linked order', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id]);
    $order = Order::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'order_number' => '#1042',
        'tracking_number' => 'AB123',
    ]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'order_id' => $order->id,
        'customer_name' => 'Jamie Buyer',
    ]);

    $rendered = app(RenderReplyTemplateAction::class)->handle(
        'Hi {customer_name}, order {order_number} is on the way! Tracking: {tracking}',
        $thread,
    );

    expect($rendered)->toBe('Hi Jamie Buyer, order #1042 is on the way! Tracking: AB123');
});

test('it falls back to empty order variables and "there" for a missing customer name when the thread has no linked order', function () {
    $thread = InboxThread::factory()->create(['order_id' => null, 'customer_name' => null]);

    $rendered = app(RenderReplyTemplateAction::class)->handle(
        'Hi {customer_name}, order {order_number}. Tracking: {tracking}',
        $thread,
    );

    expect($rendered)->toBe('Hi there, order . Tracking: ');
});
