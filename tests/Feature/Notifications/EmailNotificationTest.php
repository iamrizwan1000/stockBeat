<?php

use App\Actions\Notifications\SendEmailNotificationAction;
use App\Mail\RuleNotificationMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    Mail::fake();
});

function teamWithOwnerMembership(): Team
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => TeamMember::ROLE_OWNER]);

    return $team;
}

test('an email sends and is logged when under quota', function () {
    $team = teamWithOwnerMembership();

    $status = app(SendEmailNotificationAction::class)->handle($team, $team->owner, 'Title', 'Body');

    expect($status)->toBe('sent');
    Mail::assertQueued(RuleNotificationMail::class);
    expect(Notification::query()->where('type', Notification::TYPE_RULE_EMAIL)->count())->toBe(1);
});

test('email is skipped once the free plan monthly quota is hit', function () {
    $team = teamWithOwnerMembership();

    foreach (range(1, 25) as $i) {
        Notification::factory()->create([
            'user_id' => $team->owner_id,
            'type' => Notification::TYPE_RULE_EMAIL,
        ]);
    }

    $status = app(SendEmailNotificationAction::class)->handle($team, $team->owner, 'Title', 'Body');

    expect($status)->toBe('quota_exceeded');
    Mail::assertNothingQueued();
});

test('email is muted when the recipient has email disabled, but still logged', function () {
    $team = teamWithOwnerMembership();
    NotificationPreference::factory()->create(['user_id' => $team->owner_id, 'email_enabled' => false]);

    $status = app(SendEmailNotificationAction::class)->handle($team, $team->owner, 'Title', 'Body');

    expect($status)->toBe('muted_by_preference');
    Mail::assertNothingQueued();
    expect(Notification::query()->where('type', Notification::TYPE_RULE_EMAIL)->count())->toBe(1);
});
