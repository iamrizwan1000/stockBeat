<?php

use App\Actions\Orders\IngestOrderAction;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function connectionWithBaseCurrency(string $baseCurrency = 'USD'): StoreConnection
{
    $owner = User::factory()->create(['base_currency' => $baseCurrency]);
    $team = Team::factory()->create(['owner_id' => $owner->id]);

    return StoreConnection::factory()->create(['team_id' => $team->id]);
}

function normalizedOrder(array $overrides = []): NormalizedOrder
{
    return new NormalizedOrder(
        externalId: $overrides['externalId'] ?? '1001',
        orderNumber: $overrides['orderNumber'] ?? '#1001',
        status: $overrides['status'] ?? Order::STATUS_NEW,
        fulfillmentStatus: $overrides['fulfillmentStatus'] ?? Order::FULFILLMENT_UNFULFILLED,
        paymentStatus: $overrides['paymentStatus'] ?? Order::PAYMENT_PAID,
        currency: $overrides['currency'] ?? 'USD',
        total: $overrides['total'] ?? 49.99,
        customerName: $overrides['customerName'] ?? 'Jamie Buyer',
        customerEmail: $overrides['customerEmail'] ?? 'buyer@example.com',
        shippingAddress: $overrides['shippingAddress'] ?? ['line1' => '123 Main St'],
        placedAt: $overrides['placedAt'] ?? now(),
        shipByAt: $overrides['shipByAt'] ?? null,
        tags: $overrides['tags'] ?? [],
        raw: $overrides['raw'] ?? [],
        isTest: $overrides['isTest'] ?? false,
        items: $overrides['items'] ?? [
            new NormalizedOrderItem(externalId: 'i1', sku: 'SKU-1', title: 'Widget', imageUrl: null, qty: 2, price: 24.99),
        ],
    );
}

test('ingesting a new order creates it with items and a single created event', function () {
    $connection = connectionWithBaseCurrency();

    $order = app(IngestOrderAction::class)->handle($connection, normalizedOrder());

    expect(Order::query()->count())->toBe(1);
    expect($order->items()->count())->toBe(1);
    expect($order->events()->count())->toBe(1);
    expect($order->events()->first()->type)->toBe(OrderEvent::TYPE_CREATED);
});

test('re-ingesting the same external_id updates the same order and never re-fires created', function () {
    $connection = connectionWithBaseCurrency();

    app(IngestOrderAction::class)->handle($connection, normalizedOrder(['status' => Order::STATUS_NEW]));
    $order = app(IngestOrderAction::class)->handle($connection, normalizedOrder(['status' => Order::STATUS_SHIPPED]));

    expect(Order::query()->count())->toBe(1);
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    $events = $order->events()->orderBy('id')->pluck('type');
    expect($events->toArray())->toBe([OrderEvent::TYPE_CREATED, OrderEvent::TYPE_UPDATED]);
});

test('duplicate ingests with identical data do not create duplicate orders', function () {
    $connection = connectionWithBaseCurrency();

    app(IngestOrderAction::class)->handle($connection, normalizedOrder());
    app(IngestOrderAction::class)->handle($connection, normalizedOrder());
    app(IngestOrderAction::class)->handle($connection, normalizedOrder());

    expect(Order::query()->count())->toBe(1);
});

test('items are replaced on re-ingest to match the latest normalized set', function () {
    $connection = connectionWithBaseCurrency();

    app(IngestOrderAction::class)->handle($connection, normalizedOrder([
        'items' => [
            new NormalizedOrderItem(externalId: 'i1', sku: 'SKU-1', title: 'Widget', imageUrl: null, qty: 1, price: 10),
        ],
    ]));

    $order = app(IngestOrderAction::class)->handle($connection, normalizedOrder([
        'items' => [
            new NormalizedOrderItem(externalId: 'i2', sku: 'SKU-2', title: 'Gadget', imageUrl: null, qty: 3, price: 15),
        ],
    ]));

    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->sku)->toBe('SKU-2');
});

test('total_base_currency matches total when currencies align, else null', function () {
    $connection = connectionWithBaseCurrency('USD');

    $matching = app(IngestOrderAction::class)->handle($connection, normalizedOrder(['currency' => 'USD', 'total' => 100]));
    expect($matching->total_base_currency)->toBe(100.0);

    $mismatched = app(IngestOrderAction::class)->handle($connection, normalizedOrder(['externalId' => '1002', 'currency' => 'GBP', 'total' => 80]));
    expect($mismatched->total_base_currency)->toBeNull();
});

test('the is_test flag is stored', function () {
    $connection = connectionWithBaseCurrency();

    $order = app(IngestOrderAction::class)->handle($connection, normalizedOrder(['isTest' => true]));

    expect($order->is_test)->toBeTrue();
});
