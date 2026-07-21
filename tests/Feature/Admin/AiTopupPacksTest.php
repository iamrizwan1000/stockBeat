<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AiTopupPack;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an AI top-up pack can be created, updated, and deleted, each logging an audit entry', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'ai_50',
            'name' => '50 AI questions',
            'ai_questions' => 50,
            'price_usd' => 4.99,
            'active' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $pack = AiTopupPack::query()->where('key', 'ai_50')->firstOrFail();
    expect($pack->name)->toBe('50 AI questions');
    expect($pack->ai_questions)->toBe(50);
    expect((float) $pack->price_usd)->toBe(4.99);
    expect(AdminAuditLog::query()->where('action', 'ai_topup_pack.create')->where('target_id', $pack->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/ai-packs/{$pack->id}", [
            'name' => '50 AI questions (updated)',
            'ai_questions' => 75,
            'price_usd' => 5.99,
            'active' => false,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    $pack->refresh();
    expect($pack->name)->toBe('50 AI questions (updated)');
    expect($pack->ai_questions)->toBe(75);
    expect((float) $pack->price_usd)->toBe(5.99);
    expect($pack->active)->toBeFalse();
    expect(AdminAuditLog::query()->where('action', 'ai_topup_pack.update')->where('target_id', $pack->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/ai-packs/{$pack->id}")
        ->assertRedirect();

    expect(AiTopupPack::query()->find($pack->id))->toBeNull();
    expect(AdminAuditLog::query()->where('action', 'ai_topup_pack.delete')->where('target_id', $pack->id)->exists())->toBeTrue();
});

test('the key cannot be changed via update', function () {
    $admin = AdminUser::factory()->create();
    $pack = AiTopupPack::factory()->create(['key' => 'ai_50']);

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/ai-packs/{$pack->id}", [
            'key' => 'ai_999',
            'name' => 'Updated name',
            'ai_questions' => 50,
            'price_usd' => 4.99,
        ])
        ->assertRedirect();

    expect($pack->fresh()->key)->toBe('ai_50');
});

test('the key must be a lowercase-underscore slug and unique', function () {
    $admin = AdminUser::factory()->create();
    AiTopupPack::factory()->create(['key' => 'ai_50']);

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'Not A Valid Key!',
            'name' => 'Bad key',
            'ai_questions' => 50,
            'price_usd' => 4.99,
        ])
        ->assertSessionHasErrors('key');

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'ai_50',
            'name' => 'Duplicate key',
            'ai_questions' => 50,
            'price_usd' => 4.99,
        ])
        ->assertSessionHasErrors('key');
});

test('ai_questions must be a positive integer and price_usd must be a positive number', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'ai_zero',
            'name' => 'Zero questions',
            'ai_questions' => 0,
            'price_usd' => 4.99,
        ])
        ->assertSessionHasErrors('ai_questions');

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'ai_free',
            'name' => 'Free pack',
            'ai_questions' => 50,
            'price_usd' => 0,
        ])
        ->assertSessionHasErrors('price_usd');
});

test('a readonly admin cannot create, update, or delete an AI top-up pack', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $pack = AiTopupPack::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/ai-packs', [
            'key' => 'blocked',
            'name' => 'Blocked',
            'ai_questions' => 50,
            'price_usd' => 4.99,
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/ai-packs/{$pack->id}", [
            'name' => 'Blocked update',
            'ai_questions' => 50,
            'price_usd' => 4.99,
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/ai-packs/{$pack->id}")
        ->assertForbidden();
});
