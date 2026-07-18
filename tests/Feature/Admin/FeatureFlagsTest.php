<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the feature flags page requires admin authentication', function () {
    test()->get('/admin/feature-flags')->assertRedirect('/admin/login');
});

test('a feature flag can be created, updated, and deleted, each logging an audit entry', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', [
            'key' => 'new_rules_ui',
            'name' => 'New rules UI',
            'description' => 'Redesigned rule builder.',
            'enabled' => true,
            'rollout_percentage' => 25,
        ])
        ->assertRedirect();

    $flag = FeatureFlag::query()->where('key', 'new_rules_ui')->firstOrFail();
    expect($flag->name)->toBe('New rules UI');
    expect($flag->rollout_percentage)->toBe(25);
    expect(AdminAuditLog::query()->where('action', 'feature_flag.create')->where('target_id', $flag->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->put("/admin/feature-flags/{$flag->id}", [
            'name' => 'New rules UI (v2)',
            'description' => 'Redesigned rule builder.',
            'enabled' => true,
            'rollout_percentage' => 50,
            'enabled_for_team_ids' => [1, 2],
        ])
        ->assertRedirect();

    $flag->refresh();
    expect($flag->name)->toBe('New rules UI (v2)');
    expect($flag->rollout_percentage)->toBe(50);
    expect($flag->enabled_for_team_ids)->toBe([1, 2]);
    expect(AdminAuditLog::query()->where('action', 'feature_flag.update')->where('target_id', $flag->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/feature-flags/{$flag->id}")
        ->assertRedirect();

    expect(FeatureFlag::query()->find($flag->id))->toBeNull();
    expect(AdminAuditLog::query()->where('action', 'feature_flag.delete')->where('target_id', $flag->id)->exists())->toBeTrue();
});

test('the key cannot be changed via update', function () {
    $admin = AdminUser::factory()->create();
    $flag = FeatureFlag::factory()->create(['key' => 'original_key']);

    test()->actingAs($admin, 'admin')
        ->put("/admin/feature-flags/{$flag->id}", [
            'key' => 'attempted_new_key',
            'name' => 'Updated name',
            'enabled' => true,
            'rollout_percentage' => 10,
        ])
        ->assertRedirect();

    expect($flag->fresh()->key)->toBe('original_key');
});

test('the key must be a lowercase-underscore slug', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', [
            'key' => 'Not A Valid Key!',
            'name' => 'Bad key',
        ])
        ->assertSessionHasErrors('key');
});

test('rollout percentage must be between 0 and 100', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', [
            'key' => 'out_of_range',
            'name' => 'Out of range',
            'rollout_percentage' => 150,
        ])
        ->assertSessionHasErrors('rollout_percentage');
});

test('a readonly admin cannot create, update, or delete a feature flag', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $flag = FeatureFlag::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', ['key' => 'blocked', 'name' => 'Blocked'])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->put("/admin/feature-flags/{$flag->id}", ['name' => 'Blocked update'])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/feature-flags/{$flag->id}")
        ->assertForbidden();
});

test('a superadmin can create a feature flag', function () {
    $admin = AdminUser::factory()->superadmin()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', ['key' => 'allowed', 'name' => 'Allowed'])
        ->assertRedirect();

    expect(FeatureFlag::query()->where('key', 'allowed')->exists())->toBeTrue();
});

test('a support admin can create a feature flag', function () {
    $admin = AdminUser::factory()->create(); // default role is support

    test()->actingAs($admin, 'admin')
        ->post('/admin/feature-flags', ['key' => 'allowed_support', 'name' => 'Allowed'])
        ->assertRedirect();

    expect(FeatureFlag::query()->where('key', 'allowed_support')->exists())->toBeTrue();
});
