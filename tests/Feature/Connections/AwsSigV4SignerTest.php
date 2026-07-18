<?php

use App\Support\Connections\Adapters\Amazon\AwsSigV4Signer;

test('sign produces a well-formed SigV4 Authorization header and required headers', function () {
    $signer = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', null, 'us-east-1', 'execute-api');

    $headers = $signer->sign('GET', 'sellingpartnerapi-na.amazon.com', '/orders/v0/orders', ['MarketplaceIds' => 'ATVPDKIKX0DER'], '');

    expect($headers)->toHaveKeys(['Authorization', 'X-Amz-Date', 'X-Amz-Content-Sha256']);
    expect($headers['Authorization'])->toStartWith('AWS4-HMAC-SHA256 Credential=AKIAFAKE/');
    expect($headers['Authorization'])->toContain('/us-east-1/execute-api/aws4_request');
    expect($headers['Authorization'])->toContain('SignedHeaders=host;x-amz-content-sha256;x-amz-date');
    expect($headers)->not->toHaveKey('X-Amz-Security-Token');
});

test('sign includes and signs X-Amz-Security-Token when a session token is present', function () {
    $signer = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', 'fake-session-token', 'us-east-1', 'execute-api');

    $headers = $signer->sign('POST', 'sellingpartnerapi-na.amazon.com', '/feeds/2021-06-30/feeds', [], '{}');

    expect($headers['X-Amz-Security-Token'])->toBe('fake-session-token');
    expect($headers['Authorization'])->toContain('SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token');
});

test('sign is deterministic for identical inputs', function () {
    $signer = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', null, 'us-east-1', 'execute-api');

    $first = $signer->sign('GET', 'host.example.com', '/path', [], '');
    $second = $signer->sign('GET', 'host.example.com', '/path', [], '');

    expect($first['Authorization'])->toBe($second['Authorization']);
});

test('sign produces a different signature when the body changes', function () {
    $signer = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', null, 'us-east-1', 'execute-api');

    $first = $signer->sign('POST', 'host.example.com', '/path', [], 'body-a');
    $second = $signer->sign('POST', 'host.example.com', '/path', [], 'body-b');

    expect($first['Authorization'])->not->toBe($second['Authorization']);
    expect($first['X-Amz-Content-Sha256'])->not->toBe($second['X-Amz-Content-Sha256']);
});

test('sign produces a different signature for a different service (e.g. sts vs execute-api)', function () {
    $executeApiSigner = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', null, 'us-east-1', 'execute-api');
    $stsSigner = new AwsSigV4Signer('AKIAFAKE', 'fake-secret', null, 'us-east-1', 'sts');

    $first = $executeApiSigner->sign('POST', 'host.example.com', '/', [], 'body');
    $second = $stsSigner->sign('POST', 'host.example.com', '/', [], 'body');

    expect($first['Authorization'])->not->toBe($second['Authorization']);
});
