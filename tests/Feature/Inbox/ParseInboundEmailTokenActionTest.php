<?php

use App\Actions\Inbox\ParseInboundEmailTokenAction;

test('a valid support address parses to its prefix and id', function () {
    expect(app(ParseInboundEmailTokenAction::class)->handle('support+42@mail.stockbeat.app'))
        ->toBe(['prefix' => 'support', 'id' => 42]);
});

test('a valid thread address parses to its prefix and id', function () {
    expect(app(ParseInboundEmailTokenAction::class)->handle('thread+17@mail.stockbeat.app'))
        ->toBe(['prefix' => 'thread', 'id' => 17]);
});

test('an address with no plus-tag is rejected', function () {
    expect(app(ParseInboundEmailTokenAction::class)->handle('hello@mail.stockbeat.app'))->toBeNull();
});

test('an unrecognized prefix is rejected', function () {
    expect(app(ParseInboundEmailTokenAction::class)->handle('billing+42@mail.stockbeat.app'))->toBeNull();
});

test('a non-numeric id is rejected', function () {
    expect(app(ParseInboundEmailTokenAction::class)->handle('support+abc@mail.stockbeat.app'))->toBeNull();
});
