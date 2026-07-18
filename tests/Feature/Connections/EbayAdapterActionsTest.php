<?php

use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayOrderForActions(array $overrides = []): Order
{
    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    return Order::factory()->create(array_merge([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'platform' => StoreConnection::PLATFORM_EBAY,
        'external_id' => '11-22333-44555',
        'currency' => 'USD',
        'total' => 100.00,
    ], $overrides));
}

test('fulfill looks up line items and submits a shipping fulfillment', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555' => Http::response([
            'lineItems' => [['lineItemId' => 'li-1', 'quantity' => 2]],
        ], 200),
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/shipping_fulfillment' => Http::response(['fulfillmentId' => '1'], 201),
    ]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->fulfill($order, new FulfillmentData('1Z999', 'UPS'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'shipping_fulfillment')
        && ($request['lineItems'][0]['lineItemId'] ?? null) === 'li-1'
        && ($request['trackingNumber'] ?? null) === '1Z999');
});

test('fulfill fails cleanly when the order has no line items', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555' => Http::response(['lineItems' => []], 200),
    ]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->fulfill($order, new FulfillmentData('1Z999'));

    expect($result->success)->toBeFalse();
});

test('refund issues an order-level refund', function () {
    Http::fake([
        'api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/issue_refund' => Http::response(['refundId' => '1'], 200),
    ]);

    $order = ebayOrderForActions(['total' => 100.00, 'currency' => 'USD']);

    $result = app(EbayAdapter::class)->refund($order, new RefundData(amount: 100.00, reason: 'not as described'));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_REFUNDED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'issue_refund')
        && ($request['orderLevelRefundAmount']['value'] ?? null) === '100'
        && ($request['orderLevelRefundAmount']['currency'] ?? null) === 'USD');
});

test('a partial refund marks the order partially refunded', function () {
    Http::fake(['api.sandbox.ebay.com/sell/fulfillment/v1/order/11-22333-44555/issue_refund' => Http::response([], 200)]);

    $order = ebayOrderForActions(['total' => 100.00]);

    $result = app(EbayAdapter::class)->refund($order, new RefundData(amount: 20.00));

    expect($result->success)->toBeTrue();
    expect($order->fresh()->payment_status)->toBe(Order::PAYMENT_PARTIALLY_REFUNDED);
});

test('cancel calls the post-order cancellation endpoint', function () {
    Http::fake(['api.sandbox.ebay.com/post-order/v2/cancellation' => Http::response(['cancelId' => '1'], 200)]);

    $order = ebayOrderForActions();

    $result = app(EbayAdapter::class)->cancel($order, 'Out of stock');

    expect($result->success)->toBeTrue();
    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/post-order/v2/cancellation')
        && ($request['legacyOrderId'] ?? null) === '11-22333-44555');
});

test('refreshAuth updates the access token and expiry on success', function () {
    Http::fake(['api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response([
        'access_token' => 'new-token',
        'expires_in' => 7200,
    ], 200)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'old-token', 'refresh_token' => 'refresh-abc', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EbayAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->credentials['access_token'])->toBe('new-token');
    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_ACTIVE);
});

test('sendMessage posts the correct AddMemberMessageAAQToPartner XML and reports success', function () {
    $successXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <AddMemberMessageAAQToPartnerResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
    </AddMemberMessageAAQToPartnerResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($successXml, 200)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    $thread = InboxThread::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'channel' => StoreConnection::PLATFORM_EBAY,
        'external_buyer_username' => 'buyer123',
        'external_item_id' => '110445566778',
    ]);

    $result = app(EbayAdapter::class)->sendMessage($thread, 'Your order shipped yesterday!');

    expect($result->success)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.sandbox.ebay.com/ws/api.dll')
            && $request->hasHeader('X-EBAY-API-CALL-NAME', 'AddMemberMessageAAQToPartner')
            && $request->hasHeader('X-EBAY-API-IAF-TOKEN', 'fake-token')
            && str_contains($request->body(), '<ItemID>110445566778</ItemID>')
            && str_contains($request->body(), '<RecipientID>buyer123</RecipientID>')
            && str_contains($request->body(), '<Body>Your order shipped yesterday!</Body>');
    });
});

test('sendMessage reports failure when eBay Acks Failure even with a 200 status', function () {
    $failureXml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <AddMemberMessageAAQToPartnerResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Failure</Ack>
    </AddMemberMessageAAQToPartnerResponse>
    XML;

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response($failureXml, 200)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token', 'expires_at' => now()->addHour()->toIso8601String()],
    ]);

    $thread = InboxThread::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'channel' => StoreConnection::PLATFORM_EBAY,
        'external_buyer_username' => 'buyer123',
        'external_item_id' => '110445566778',
    ]);

    $result = app(EbayAdapter::class)->sendMessage($thread, 'Hello');

    expect($result->success)->toBeFalse();
});

test('sendMessage fails cleanly when the thread has no buyer username or item id', function () {
    $connection = StoreConnection::factory()->create(['platform' => StoreConnection::PLATFORM_EBAY]);

    $thread = InboxThread::factory()->create([
        'connection_id' => $connection->id,
        'team_id' => $connection->team_id,
        'channel' => StoreConnection::PLATFORM_EBAY,
        'external_buyer_username' => null,
        'external_item_id' => null,
    ]);

    $result = app(EbayAdapter::class)->sendMessage($thread, 'Hello');

    expect($result->success)->toBeFalse();
});

test('fetchMemberMessages parses inbound buyer messages from the legacy XML response', function () {
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

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'fake-token'],
    ]);

    $messages = app(EbayAdapter::class)->fetchMemberMessages($connection, now()->subDay());

    expect($messages)->toHaveCount(1);
    expect($messages[0]['external_id'])->toBe('msg-1');
    expect($messages[0]['buyer_username'])->toBe('buyer123');
    expect($messages[0]['item_id'])->toBe('110445566778');
    expect($messages[0]['body'])->toBe('Where is my order?');

    Http::assertSent(fn ($request) => $request->hasHeader('X-EBAY-API-CALL-NAME', 'GetMemberMessages'));
});

test('refreshAuth marks needs_reauth when the refresh call fails', function () {
    Http::fake(['api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $connection = StoreConnection::factory()->create([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'credentials' => ['access_token' => 'old-token', 'refresh_token' => 'refresh-abc', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);

    app(EbayAdapter::class)->refreshAuth($connection);

    expect($connection->fresh()->status)->toBe(StoreConnection::STATUS_NEEDS_REAUTH);
});
