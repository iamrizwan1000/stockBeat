<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\ContentBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the plans page requires admin authentication', function () {
    test()->get('/admin/plans')->assertRedirect('/admin/login');
});

test('a content block can be created, updated, and deleted, each logging an audit entry', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/content-blocks', [
            'key' => 'paywall_pro_headline',
            'title' => 'Pro — headline',
            'body' => 'Pro — $17.99/month',
            'locale' => 'en',
            'active' => true,
        ])
        ->assertRedirect();

    $block = ContentBlock::query()->where('key', 'paywall_pro_headline')->firstOrFail();
    expect($block->title)->toBe('Pro — headline');
    expect($block->body)->toBe('Pro — $17.99/month');
    expect(AdminAuditLog::query()->where('action', 'content_block.create')->where('target_id', $block->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/content-blocks/{$block->id}", [
            'title' => 'Pro — headline (v2)',
            'body' => 'Pro — $19.99/month',
            'locale' => 'en',
            'active' => false,
        ])
        ->assertRedirect();

    $block->refresh();
    expect($block->title)->toBe('Pro — headline (v2)');
    expect($block->body)->toBe('Pro — $19.99/month');
    expect($block->active)->toBeFalse();
    expect(AdminAuditLog::query()->where('action', 'content_block.update')->where('target_id', $block->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/content-blocks/{$block->id}")
        ->assertRedirect();

    expect(ContentBlock::query()->find($block->id))->toBeNull();
    expect(AdminAuditLog::query()->where('action', 'content_block.delete')->where('target_id', $block->id)->exists())->toBeTrue();
});

test('the key cannot be changed via update', function () {
    $admin = AdminUser::factory()->create();
    $block = ContentBlock::factory()->create(['key' => 'original_key']);

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/content-blocks/{$block->id}", [
            'key' => 'attempted_new_key',
            'title' => 'Updated title',
            'body' => 'Updated body',
        ])
        ->assertRedirect();

    expect($block->fresh()->key)->toBe('original_key');
});

test('the key must be a lowercase-underscore slug and unique', function () {
    $admin = AdminUser::factory()->create();
    ContentBlock::factory()->create(['key' => 'existing_key']);

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/content-blocks', [
            'key' => 'Not A Valid Key!',
            'title' => 'Bad key',
            'body' => 'Body text',
        ])
        ->assertSessionHasErrors('key');

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/content-blocks', [
            'key' => 'existing_key',
            'title' => 'Duplicate key',
            'body' => 'Body text',
        ])
        ->assertSessionHasErrors('key');
});

test('title and body are required', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/content-blocks', [
            'key' => 'missing_fields',
        ])
        ->assertSessionHasErrors(['title', 'body']);
});

test('a readonly admin cannot create, update, or delete a content block', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $block = ContentBlock::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/content-blocks', [
            'key' => 'blocked',
            'title' => 'Blocked',
            'body' => 'Blocked body',
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/content-blocks/{$block->id}", [
            'title' => 'Blocked update',
            'body' => 'Blocked body',
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/content-blocks/{$block->id}")
        ->assertForbidden();
});
