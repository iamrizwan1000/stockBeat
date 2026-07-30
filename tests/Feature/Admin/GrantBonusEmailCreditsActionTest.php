<?php

use App\Actions\Admin\GrantBonusEmailCreditsAction;
use App\Models\AdminUser;
use App\Models\EmailUsageLedger;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('granting credits records the cumulative bonus granted this month', function () {
    $admin = AdminUser::factory()->create();
    $team = Team::factory()->create();

    app(GrantBonusEmailCreditsAction::class)->handle($admin, $team, 15);
    $entry = app(GrantBonusEmailCreditsAction::class)->handle($admin, $team, 5);

    expect($entry->balance_after)->toBe(20);
    expect(EmailUsageLedger::bonusGrantedThisMonth($team->id))->toBe(20);
});

test('effectiveMonthlyLimit stays null (unlimited) regardless of bonus grants', function () {
    $team = Team::factory()->create();
    EmailUsageLedger::query()->create([
        'team_id' => $team->id,
        'delta' => 100,
        'reason' => EmailUsageLedger::REASON_TOPUP_IAP,
        'balance_after' => 100,
    ]);

    expect(EmailUsageLedger::effectiveMonthlyLimit($team->id, null))->toBeNull();
});
