<?php

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('subscribing creates a subscriber and queues a confirmation email', function () {
    Mail::fake();

    test()->post('/newsletter/subscribe', ['email' => 'visitor@example.com'])
        ->assertRedirect();

    $subscriber = NewsletterSubscriber::query()->where('email', 'visitor@example.com')->first();
    expect($subscriber)->not->toBeNull();
    expect($subscriber->unsubscribed_at)->toBeNull();
    expect($subscriber->unsubscribe_token)->not->toBeEmpty();

    Mail::assertQueued(NewsletterConfirmationMail::class);
});

test('subscribing requires a valid email', function () {
    test()->post('/newsletter/subscribe', ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

test('re-subscribing after an unsubscribe clears unsubscribed_at instead of creating a duplicate', function () {
    Mail::fake();
    $subscriber = NewsletterSubscriber::factory()->create([
        'email' => 'visitor@example.com',
        'unsubscribed_at' => now(),
    ]);

    test()->post('/newsletter/subscribe', ['email' => 'visitor@example.com'])->assertRedirect();

    expect(NewsletterSubscriber::query()->where('email', 'visitor@example.com')->count())->toBe(1);
    expect($subscriber->fresh()->unsubscribed_at)->toBeNull();
});

test('the signed unsubscribe link flips unsubscribed_at', function () {
    $subscriber = NewsletterSubscriber::factory()->create();

    $url = URL::signedRoute('newsletter.unsubscribe', ['token' => $subscriber->unsubscribe_token]);

    test()->get($url)->assertOk();

    expect($subscriber->fresh()->unsubscribed_at)->not->toBeNull();
});

test('an unsigned unsubscribe request is rejected', function () {
    $subscriber = NewsletterSubscriber::factory()->create();

    test()->get("/newsletter/unsubscribe/{$subscriber->unsubscribe_token}")->assertForbidden();

    expect($subscriber->fresh()->unsubscribed_at)->toBeNull();
});
