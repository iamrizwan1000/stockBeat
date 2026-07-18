<?php

use App\Actions\Inbox\SendInboxMessageAction;
use App\Mail\InboxMessageMail;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.inbound_email.domain' => 'mail.stockbeat.app']);
    config(['services.ebay.env' => 'sandbox']);
    Mail::fake();
});

test('sending a message with a known customer email queues it and marks it sent', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => 'buyer@example.com']);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Your order shipped!');

    expect($message->direction)->toBe(InboxMessage::DIRECTION_OUT);
    expect($message->status)->toBe(InboxMessage::STATUS_SENT);
    expect($message->sent_by)->toBe($owner->id);
    expect($thread->fresh()->last_message_at)->not->toBeNull();
    Mail::assertQueued(InboxMessageMail::class);
});

test('sending a message with no customer email on the thread fails without sending mail', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $thread = InboxThread::factory()->create(['team_id' => $team->id, 'customer_email' => null]);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Hello');

    expect($message->status)->toBe(InboxMessage::STATUS_FAILED);
    expect($message->failure_reason)->not->toBeNull();
    Mail::assertNothingQueued();
});

test('a woo channel thread routes through email even when a body is provided', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'platform' => StoreConnection::PLATFORM_WOO]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'channel' => StoreConnection::PLATFORM_WOO,
        'customer_email' => 'buyer@example.com',
    ]);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Shipped!');

    expect($message->status)->toBe(InboxMessage::STATUS_SENT);
    Mail::assertQueued(InboxMessageMail::class);
});

test('an ebay channel thread routes through EbayAdapter::sendMessage and succeeds', function () {
    $successXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <AddMemberMessageAAQToPartnerResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
    </AddMemberMessageAAQToPartnerResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($successXml, 200)]);

    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token'],
    ]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'channel' => StoreConnection::PLATFORM_EBAY,
        'external_buyer_username' => 'buyer123',
        'external_item_id' => '110445566778',
        'customer_email' => null,
    ]);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'It shipped!');

    expect($message->status)->toBe(InboxMessage::STATUS_SENT);
    Mail::assertNothingQueued();
});

test('an etsy channel thread not yet approved for conversations is marked failed with a clear reason, not a 500', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_ETSY,
        'credentials' => ['access_token' => '1.fake-token', 'shop_id' => 555111],
    ]);
    $thread = InboxThread::factory()->create([
        'team_id' => $team->id,
        'connection_id' => $connection->id,
        'channel' => StoreConnection::PLATFORM_ETSY,
        'customer_email' => null,
    ]);

    $message = app(SendInboxMessageAction::class)->handle($owner, $thread, 'Hello');

    expect($message->status)->toBe(InboxMessage::STATUS_FAILED);
    expect($message->failure_reason)->toContain('conversations');
});
