<?php

use App\Actions\Connections\ComputeStoreConnectionFingerprintAction;
use App\Models\StoreConnection;

test('two connections to the same store url produce the same fingerprint', function () {
    $action = app(ComputeStoreConnectionFingerprintAction::class);

    $a = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://example-shop.test']);
    $b = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://example-shop.test/']);

    expect($a)->not->toBeNull();
    expect($a)->toBe($b);
});

test('different store urls produce different fingerprints', function () {
    $action = app(ComputeStoreConnectionFingerprintAction::class);

    $a = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://shop-a.test']);
    $b = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://shop-b.test']);

    expect($a)->not->toBe($b);
});

test('a store url is compared case-insensitively', function () {
    $action = app(ComputeStoreConnectionFingerprintAction::class);

    $a = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://Example-Shop.test']);
    $b = $action->handle(StoreConnection::PLATFORM_WOO, ['store_url' => 'https://example-shop.test']);

    expect($a)->toBe($b);
});

test('credentials without a store_url produce no fingerprint', function () {
    $action = app(ComputeStoreConnectionFingerprintAction::class);

    expect($action->handle(StoreConnection::PLATFORM_SHOPIFY, []))->toBeNull();
    expect($action->handle(StoreConnection::PLATFORM_SHOPIFY, ['store_url' => '']))->toBeNull();
});
