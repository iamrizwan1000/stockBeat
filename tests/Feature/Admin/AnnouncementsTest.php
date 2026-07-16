<?php

use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('an announcement can be created, updated, and deleted', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/announcements', ['title' => 'New feature', 'body' => 'Order spike alerts are here.'])
        ->assertRedirect();

    $announcement = Announcement::query()->where('title', 'New feature')->firstOrFail();

    test()->actingAs($admin, 'admin')
        ->put("/admin/announcements/{$announcement->id}", ['title' => 'New feature!', 'body' => 'Updated body.'])
        ->assertRedirect();

    expect($announcement->fresh()->title)->toBe('New feature!');

    test()->actingAs($admin, 'admin')
        ->delete("/admin/announcements/{$announcement->id}")
        ->assertRedirect();

    expect(Announcement::query()->find($announcement->id))->toBeNull();
});

test('a readonly admin cannot create an announcement', function () {
    $admin = AdminUser::factory()->readonly()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/announcements', ['title' => 'x', 'body' => 'y'])
        ->assertForbidden();
});

test('the mobile endpoint only returns currently active, audience-matched announcements', function () {
    $user = User::factory()->create(['marketing_opt_in' => false]);
    Sanctum::actingAs($user);

    $active = Announcement::factory()->create(['title' => 'Active one', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Not started yet', 'starts_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Already ended', 'ends_at' => now()->subDay()]);
    Announcement::factory()->create(['title' => 'Wrong audience', 'audience' => ['marketing_opt_in' => true]]);

    $response = test()->getJson('/api/v1/announcements');

    $response->assertOk();
    $titles = collect($response->json('data.announcements'))->pluck('title');
    expect($titles->all())->toBe([$active->title]);
});
