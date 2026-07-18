<?php

use App\Actions\Inbox\IngestEbayMemberMessageAction;
use App\Jobs\PollEbayMessagesJob;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreConnection;
use App\Models\User;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayConnectionForMessagePolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ], $overrides));
}

function runEbayMessagePollJob(int $connectionId): void
{
    (new PollEbayMessagesJob($connectionId))->handle(app(EbayAdapter::class), app(IngestEbayMemberMessageAction::class));
}

test('the poller ingests a new inbound member message into a matching order thread', function () {
    $connection = ebayConnectionForMessagePolling();
    $team = $connection->team;
    $owner = User::factory()->create();
    $team->update(['owner_id' => $owner->id]);

    $order = Order::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_EBAY,
        'external_id' => '11-22333-44555',
        'buyer_username' => 'buyer123',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'legacy_item_id' => '110445566778',
    ]);

    $responseXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <GetMemberMessagesResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
        <MemberMessageExchange>
            <MemberMessage>
                <MessageID>msg-1</MessageID>
                <Sender>buyer123</Sender>
                <ItemID>110445566778</ItemID>
                <Body>Where is my order?</Body>
                <CreationDate>2026-07-18T10:00:00.000Z</CreationDate>
            </MemberMessage>
        </MemberMessageExchange>
    </GetMemberMessagesResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($responseXml, 200)]);

    runEbayMessagePollJob($connection->id);

    $message = InboxMessage::query()->where('external_id', 'msg-1')->first();

    expect($message)->not->toBeNull();
    expect($message->direction)->toBe(InboxMessage::DIRECTION_IN);
    expect($message->body)->toBe('Where is my order?');

    $thread = InboxThread::query()->find($message->thread_id);
    expect($thread->order_id)->toBe($order->id);
    expect($thread->channel)->toBe(StoreConnection::PLATFORM_EBAY);
    expect($connection->fresh()->last_message_sync_at)->not->toBeNull();
});

test('the poller creates a standalone thread when no matching order exists yet', function () {
    $connection = ebayConnectionForMessagePolling();
    $team = $connection->team;
    $owner = User::factory()->create();
    $team->update(['owner_id' => $owner->id]);

    $responseXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <GetMemberMessagesResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
        <MemberMessageExchange>
            <MemberMessage>
                <MessageID>msg-2</MessageID>
                <Sender>presale_buyer</Sender>
                <ItemID>999888777</ItemID>
                <Body>Do you ship internationally?</Body>
                <CreationDate>2026-07-18T10:00:00.000Z</CreationDate>
            </MemberMessage>
        </MemberMessageExchange>
    </GetMemberMessagesResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($responseXml, 200)]);

    runEbayMessagePollJob($connection->id);

    $message = InboxMessage::query()->where('external_id', 'msg-2')->first();
    expect($message)->not->toBeNull();

    $thread = InboxThread::query()->find($message->thread_id);
    expect($thread->order_id)->toBeNull();
    expect($thread->external_buyer_username)->toBe('presale_buyer');
    expect($thread->external_item_id)->toBe('999888777');
});

test('re-polling the same message is idempotent', function () {
    $connection = ebayConnectionForMessagePolling();
    $team = $connection->team;
    $owner = User::factory()->create();
    $team->update(['owner_id' => $owner->id]);

    $responseXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <GetMemberMessagesResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
        <MemberMessageExchange>
            <MemberMessage>
                <MessageID>msg-3</MessageID>
                <Sender>buyer999</Sender>
                <ItemID>123123123</ItemID>
                <Body>Hello</Body>
                <CreationDate>2026-07-18T10:00:00.000Z</CreationDate>
            </MemberMessage>
        </MemberMessageExchange>
    </GetMemberMessagesResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($responseXml, 200)]);

    runEbayMessagePollJob($connection->id);
    runEbayMessagePollJob($connection->id);

    expect(InboxMessage::query()->where('external_id', 'msg-3')->count())->toBe(1);
});

test('polling a non-ebay or missing connection is a safe no-op', function () {
    runEbayMessagePollJob(999999);
})->throwsNoExceptions();
