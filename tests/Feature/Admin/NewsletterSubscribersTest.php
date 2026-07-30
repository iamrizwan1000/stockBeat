<?php

use App\Models\AdminUser;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the newsletter subscribers list requires admin authentication', function () {
    test()->get('/admin/newsletter-subscribers')->assertRedirect('/admin/login');
});

test('an admin can list subscribers and see the active count', function () {
    $admin = AdminUser::factory()->create();
    NewsletterSubscriber::factory()->create(['email' => 'active@example.com']);
    NewsletterSubscriber::factory()->create(['email' => 'gone@example.com', 'unsubscribed_at' => now()]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/newsletter-subscribers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('active_count', 1)
            ->has('subscribers.data', 2));
});

test('an admin can filter subscribers by status', function () {
    $admin = AdminUser::factory()->create();
    NewsletterSubscriber::factory()->create(['email' => 'active@example.com']);
    NewsletterSubscriber::factory()->create(['email' => 'gone@example.com', 'unsubscribed_at' => now()]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/newsletter-subscribers?status=unsubscribed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('subscribers.data', 1));
});

test('an admin can search subscribers by email', function () {
    $admin = AdminUser::factory()->create();
    NewsletterSubscriber::factory()->create(['email' => 'jamie@example.com']);
    NewsletterSubscriber::factory()->create(['email' => 'someone-else@example.com']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/newsletter-subscribers?q=jamie')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('subscribers.data', 1));
});

test('CSV export streams every matching subscriber, not just the current page', function () {
    $admin = AdminUser::factory()->create();
    NewsletterSubscriber::factory()->create(['email' => 'export-test@example.com']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/newsletter-subscribers/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('export-test@example.com');
});
