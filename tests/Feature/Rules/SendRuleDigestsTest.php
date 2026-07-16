<?php

use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('a due digest rule fires exactly once per day, then again the following day', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_DIGEST, 'enabled' => true]);

    Carbon::setTestNow(Carbon::parse('2026-01-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    Artisan::call('rules:send-digests');

    expect(RuleExecution::query()->where('rule_id', $rule->id)->where('trigger', Rule::TRIGGER_DIGEST)->count())->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-01-02 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    expect(RuleExecution::query()->where('rule_id', $rule->id)->where('trigger', Rule::TRIGGER_DIGEST)->count())->toBe(2);
});

test('a digest rule does not fire outside its configured hour', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'controls' => ['digest_time' => '09:00'],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-01-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(0);
});

test('a weekly digest rule only fires on its configured day of week', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_DIGEST,
        'enabled' => true,
        'controls' => ['digest_frequency' => 'weekly', 'digest_day_of_week' => Carbon::MONDAY],
    ]);

    // 2026-01-01 is a Thursday.
    Carbon::setTestNow(Carbon::parse('2026-01-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(0);

    // 2026-01-05 is a Monday.
    Carbon::setTestNow(Carbon::parse('2026-01-05 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('a disabled digest rule never fires', function () {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_DIGEST, 'enabled' => false]);

    Carbon::setTestNow(Carbon::parse('2026-01-01 07:00:00', 'UTC'));
    Artisan::call('rules:send-digests');

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(0);
});
