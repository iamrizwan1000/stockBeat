<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\SmsTopupPack;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the plans page requires admin authentication', function () {
    test()->get('/admin/plans')->assertRedirect('/admin/login');
});

test('an SMS top-up pack can be created, updated, and deleted, each logging an audit entry', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'sms_100',
            'name' => '100 SMS',
            'sms_credits' => 100,
            'price_usd' => 2.99,
            'active' => true,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $pack = SmsTopupPack::query()->where('key', 'sms_100')->firstOrFail();
    expect($pack->name)->toBe('100 SMS');
    expect($pack->sms_credits)->toBe(100);
    expect((float) $pack->price_usd)->toBe(2.99);
    expect(AdminAuditLog::query()->where('action', 'sms_topup_pack.create')->where('target_id', $pack->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/sms-packs/{$pack->id}", [
            'name' => '100 SMS (updated)',
            'sms_credits' => 150,
            'price_usd' => 3.49,
            'active' => false,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    $pack->refresh();
    expect($pack->name)->toBe('100 SMS (updated)');
    expect($pack->sms_credits)->toBe(150);
    expect((float) $pack->price_usd)->toBe(3.49);
    expect($pack->active)->toBeFalse();
    expect(AdminAuditLog::query()->where('action', 'sms_topup_pack.update')->where('target_id', $pack->id)->exists())->toBeTrue();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/sms-packs/{$pack->id}")
        ->assertRedirect();

    expect(SmsTopupPack::query()->find($pack->id))->toBeNull();
    expect(AdminAuditLog::query()->where('action', 'sms_topup_pack.delete')->where('target_id', $pack->id)->exists())->toBeTrue();
});

test('the key cannot be changed via update', function () {
    $admin = AdminUser::factory()->create();
    $pack = SmsTopupPack::factory()->create(['key' => 'sms_100']);

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/sms-packs/{$pack->id}", [
            'key' => 'sms_999',
            'name' => 'Updated name',
            'sms_credits' => 100,
            'price_usd' => 2.99,
        ])
        ->assertRedirect();

    expect($pack->fresh()->key)->toBe('sms_100');
});

test('the key must be a lowercase-underscore slug and unique', function () {
    $admin = AdminUser::factory()->create();
    SmsTopupPack::factory()->create(['key' => 'sms_100']);

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'Not A Valid Key!',
            'name' => 'Bad key',
            'sms_credits' => 100,
            'price_usd' => 2.99,
        ])
        ->assertSessionHasErrors('key');

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'sms_100',
            'name' => 'Duplicate key',
            'sms_credits' => 100,
            'price_usd' => 2.99,
        ])
        ->assertSessionHasErrors('key');
});

test('sms_credits must be a positive integer and price_usd must be a positive number', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'sms_zero',
            'name' => 'Zero credits',
            'sms_credits' => 0,
            'price_usd' => 2.99,
        ])
        ->assertSessionHasErrors('sms_credits');

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'sms_free',
            'name' => 'Free pack',
            'sms_credits' => 100,
            'price_usd' => 0,
        ])
        ->assertSessionHasErrors('price_usd');
});

test('a readonly admin cannot create, update, or delete an SMS top-up pack', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $pack = SmsTopupPack::factory()->create();

    test()->actingAs($admin, 'admin')
        ->post('/admin/plans/sms-packs', [
            'key' => 'blocked',
            'name' => 'Blocked',
            'sms_credits' => 100,
            'price_usd' => 2.99,
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/sms-packs/{$pack->id}", [
            'name' => 'Blocked update',
            'sms_credits' => 100,
            'price_usd' => 2.99,
        ])
        ->assertForbidden();

    test()->actingAs($admin, 'admin')
        ->delete("/admin/plans/sms-packs/{$pack->id}")
        ->assertForbidden();
});
