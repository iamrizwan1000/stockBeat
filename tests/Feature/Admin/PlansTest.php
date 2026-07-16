<?php

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Team;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('the plans page requires admin authentication', function () {
    test()->get('/admin/plans')->assertRedirect('/admin/login');
});

test('a readonly admin cannot update a plan limit', function () {
    $admin = AdminUser::factory()->readonly()->create();
    $limit = PlanLimit::query()->whereHas('plan', fn ($q) => $q->where('key', Plan::FREE))
        ->where('key', PlanLimit::MAX_STORES)->first();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/limits/{$limit->id}", ['value' => 5])
        ->assertForbidden();
});

test('updating a numeric limit persists the new value and logs it', function () {
    $admin = AdminUser::factory()->create();
    $limit = PlanLimit::query()->whereHas('plan', fn ($q) => $q->where('key', Plan::FREE))
        ->where('key', PlanLimit::MAX_STORES)->first();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/limits/{$limit->id}", ['value' => 5])
        ->assertRedirect();

    expect($limit->fresh()->value)->toBe(5);
    expect($limit->fresh()->updated_by)->toBe($admin->id);
    expect(AdminAuditLog::query()->where('action', 'plan_limit.update')->where('target_id', $limit->id)->exists())->toBeTrue();
});

test('a blank value on max_stores means unlimited (null)', function () {
    $admin = AdminUser::factory()->create();
    $limit = PlanLimit::query()->whereHas('plan', fn ($q) => $q->where('key', Plan::FREE))
        ->where('key', PlanLimit::MAX_STORES)->first();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/limits/{$limit->id}", ['value' => ''])
        ->assertRedirect();

    expect($limit->fresh()->value)->toBeNull();
});

test('a boolean limit coerces string input to a real boolean', function () {
    $admin = AdminUser::factory()->create();
    $limit = PlanLimit::query()->whereHas('plan', fn ($q) => $q->where('key', Plan::FREE))
        ->where('key', PlanLimit::INBOX_ENABLED)->first();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/limits/{$limit->id}", ['value' => 'true'])
        ->assertRedirect();

    expect($limit->fresh()->value)->toBeTrue();
});

test('updating a plan limit is reflected live in mobile entitlements', function () {
    $admin = AdminUser::factory()->create();
    $limit = PlanLimit::query()->whereHas('plan', fn ($q) => $q->where('key', Plan::FREE))
        ->where('key', PlanLimit::HISTORY_DAYS)->first();

    test()->actingAs($admin, 'admin')
        ->put("/admin/plans/limits/{$limit->id}", ['value' => 14]);

    $team = Team::factory()->create();

    $entitlements = app(ResolveEntitlementsAction::class)->handle($team);

    expect($entitlements['limits']['history_days'])->toBe(14);
});
