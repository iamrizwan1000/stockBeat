<?php

use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\ReplyTemplate;
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

function onboardedInboxSeller(): array
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

test('listing threads requires authentication', function () {
    test()->getJson('/api/v1/threads')->assertUnauthorized();
});

test('a seller only sees threads belonging to their own team', function () {
    [$userA, $connectionA] = onboardedInboxSeller();
    InboxThread::factory()->create(['team_id' => $userA->ownedTeam->id, 'connection_id' => $connectionA->id]);

    [$userB, $connectionB] = onboardedInboxSeller();
    InboxThread::factory()->create(['team_id' => $userB->ownedTeam->id, 'connection_id' => $connectionB->id]);

    Sanctum::actingAs($userA);
    test()->getJson('/api/v1/threads')->assertOk()->assertJsonCount(1, 'data.threads');
});

test('a thread exposes its connection_id so the client can look up messaging capabilities', function () {
    [$user, $connection] = onboardedInboxSeller();
    InboxThread::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    Sanctum::actingAs($user);
    test()->getJson('/api/v1/threads')
        ->assertOk()
        ->assertJsonPath('data.threads.0.connection_id', $connection->id);
});

test('sending a free-text message to a thread creates it', function () {
    [$user, $connection] = onboardedInboxSeller();
    $thread = InboxThread::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'customer_email' => 'buyer@example.com',
    ]);

    test()->postJson("/api/v1/threads/{$thread->id}/messages", ['body' => 'Hi there!'])
        ->assertCreated()
        ->assertJsonPath('data.message.body', 'Hi there!');

    expect(InboxMessage::query()->where('thread_id', $thread->id)->count())->toBe(1);
});

test('sending a message via a saved reply template renders its variables', function () {
    [$user, $connection] = onboardedInboxSeller();
    $order = Order::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'order_number' => '#2001',
        'tracking_number' => 'XYZ999',
    ]);
    $thread = InboxThread::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'order_id' => $order->id,
        'customer_name' => 'Alex Chen',
        'customer_email' => 'buyer@example.com',
    ]);
    $template = ReplyTemplate::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'body_with_variables' => 'Hi {customer_name}, order {order_number} shipped! Tracking: {tracking}',
    ]);

    test()->postJson("/api/v1/threads/{$thread->id}/messages", ['reply_template_id' => $template->id])
        ->assertCreated()
        ->assertJsonPath('data.message.body', 'Hi Alex Chen, order #2001 shipped! Tracking: XYZ999');
});

test('a reply_template_id belonging to another team is rejected', function () {
    [$user, $connection] = onboardedInboxSeller();
    $thread = InboxThread::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    $otherTemplate = ReplyTemplate::factory()->create();

    test()->postJson("/api/v1/threads/{$thread->id}/messages", ['reply_template_id' => $otherTemplate->id])
        ->assertUnprocessable();
});

test('a thread from another team cannot be messaged', function () {
    [, $connectionA] = onboardedInboxSeller();
    $thread = InboxThread::factory()->create(['connection_id' => $connectionA->id]);

    [$userB] = onboardedInboxSeller();
    Sanctum::actingAs($userB);

    test()->postJson("/api/v1/threads/{$thread->id}/messages", ['body' => 'Hello'])->assertNotFound();
});

test('assigning a thread to a team member sets assigned_to', function () {
    [$user, $connection] = onboardedInboxSeller();
    $thread = InboxThread::factory()->create(['team_id' => $user->ownedTeam->id, 'connection_id' => $connection->id]);

    test()->postJson("/api/v1/threads/{$thread->id}/assign", ['user_id' => $user->id])
        ->assertOk()
        ->assertJsonPath('data.thread.assigned_to', $user->id);
});

test('assigning with no user_id unassigns the thread', function () {
    [$user, $connection] = onboardedInboxSeller();
    $thread = InboxThread::factory()->create([
        'team_id' => $user->ownedTeam->id,
        'connection_id' => $connection->id,
        'assigned_to' => $user->id,
    ]);

    test()->postJson("/api/v1/threads/{$thread->id}/assign", [])
        ->assertOk()
        ->assertJsonPath('data.thread.assigned_to', null);
});
