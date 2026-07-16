<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the audit log page requires admin authentication', function () {
    test()->get('/admin/audit-log')->assertRedirect('/admin/login');
});

test('the audit log page lists entries newest first', function () {
    $admin = AdminUser::factory()->create();
    $older = AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'action' => 'segment.create', 'at' => now()->subDay()]);
    $newer = AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'action' => 'segment.delete', 'at' => now()]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/audit-log/index')
            ->has('entries.data', 2)
            ->where('entries.data.0.id', $newer->id)
            ->where('entries.data.1.id', $older->id)
        );
});

test('the audit log can be filtered by admin', function () {
    $adminA = AdminUser::factory()->create();
    $adminB = AdminUser::factory()->create();
    AdminAuditLog::factory()->create(['admin_id' => $adminA->id]);
    AdminAuditLog::factory()->create(['admin_id' => $adminB->id]);

    test()->actingAs($adminA, 'admin')
        ->get("/admin/audit-log?admin_id={$adminA->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

test('the audit log can be filtered by an action substring', function () {
    $admin = AdminUser::factory()->create();
    AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'action' => 'promo_campaign.create']);
    AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'action' => 'segment.create']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/audit-log?action=promo_campaign')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'promo_campaign.create')
        );
});

test('the audit log can be filtered by target type', function () {
    $admin = AdminUser::factory()->create();
    AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'target_type' => 'App\\Models\\PromoCampaign']);
    AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'target_type' => 'App\\Models\\Segment']);

    test()->actingAs($admin, 'admin')
        ->get('/admin/audit-log?target_type=App%5CModels%5CSegment')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

test('the audit log can be filtered by a date range', function () {
    $admin = AdminUser::factory()->create();
    AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'at' => now()->subDays(10)]);
    $withinRange = AdminAuditLog::factory()->create(['admin_id' => $admin->id, 'at' => now()->subDay()]);

    test()->actingAs($admin, 'admin')
        ->get('/admin/audit-log?from='.now()->subDays(3)->toDateString())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $withinRange->id)
        );
});

test('real admin actions land in the audit log with before and after', function () {
    $admin = AdminUser::factory()->superadmin()->create();
    $target = AdminUser::factory()->create(['role' => AdminUser::ROLE_SUPPORT]);

    test()->actingAs($admin, 'admin')
        ->put("/admin/team/{$target->id}/role", ['role' => AdminUser::ROLE_READONLY])
        ->assertRedirect();

    $entry = AdminAuditLog::query()->where('action', 'admin_user.update_role')->firstOrFail();
    expect($entry->admin_id)->toBe($admin->id);
    expect($entry->target_id)->toBe($target->id);
    expect($entry->before)->toBe(['role' => AdminUser::ROLE_SUPPORT]);
    expect($entry->after)->toBe(['role' => AdminUser::ROLE_READONLY]);
});
