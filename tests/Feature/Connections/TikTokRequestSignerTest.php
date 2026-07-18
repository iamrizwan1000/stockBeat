<?php

use App\Support\Connections\Adapters\TikTok\TikTokRequestSigner;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-18T12:00:00Z'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('sign returns the app_key, a fixed-point timestamp, and a hex signature', function () {
    $signer = new TikTokRequestSigner('app-key-1', 'app-secret-1');

    $signed = $signer->sign('/order/202309/orders/search', ['page_size' => '50']);

    expect($signed['app_key'])->toBe('app-key-1');
    expect($signed['timestamp'])->toBe(Carbon::now()->timestamp);
    expect($signed['sign'])->toBeString();
    expect($signed['sign'])->toMatch('/^[a-f0-9]{64}$/');
});

test('sign is deterministic for identical inputs at the same instant', function () {
    $signer = new TikTokRequestSigner('app-key-1', 'app-secret-1');

    $first = $signer->sign('/order/202309/orders/search', ['page_size' => '50']);
    $second = $signer->sign('/order/202309/orders/search', ['page_size' => '50']);

    expect($first['sign'])->toBe($second['sign']);
});

test('sign produces a different signature when the path changes', function () {
    $signer = new TikTokRequestSigner('app-key-1', 'app-secret-1');

    $first = $signer->sign('/order/202309/orders/search', []);
    $second = $signer->sign('/order/202309/orders', []);

    expect($first['sign'])->not->toBe($second['sign']);
});

test('sign produces a different signature when the body changes', function () {
    $signer = new TikTokRequestSigner('app-key-1', 'app-secret-1');

    $first = $signer->sign('/order/202309/orders/search', [], 'body-a');
    $second = $signer->sign('/order/202309/orders/search', [], 'body-b');

    expect($first['sign'])->not->toBe($second['sign']);
});

test('sign produces a different signature for a different app secret', function () {
    $first = (new TikTokRequestSigner('app-key-1', 'secret-a'))->sign('/order/202309/orders/search', []);
    $second = (new TikTokRequestSigner('app-key-1', 'secret-b'))->sign('/order/202309/orders/search', []);

    expect($first['sign'])->not->toBe($second['sign']);
});

test('sign ignores any sign/access_token keys already present in the query', function () {
    $signer = new TikTokRequestSigner('app-key-1', 'app-secret-1');

    $clean = $signer->sign('/order/202309/orders/search', ['page_size' => '50']);
    $withStaleKeys = $signer->sign('/order/202309/orders/search', ['page_size' => '50', 'sign' => 'stale', 'access_token' => 'stale-token']);

    expect($withStaleKeys['sign'])->toBe($clean['sign']);
});
