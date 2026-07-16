<?php

use App\Mail\TrialEndingMail;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

test('a trial 2 days from ending gets the day-5-equivalent reminder exactly once', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(2)]);

    Artisan::call('trials:send-reminders');
    Artisan::call('trials:send-reminders');

    expect($subscription->fresh()->trial_reminder_day5_sent_at)->not->toBeNull();
    expect($subscription->fresh()->trial_reminder_day7_sent_at)->toBeNull();
    Mail::assertQueued(TrialEndingMail::class, 1);
});

test('a trial ending today gets the final reminder', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addHours(2)]);

    Artisan::call('trials:send-reminders');

    expect($subscription->fresh()->trial_reminder_day7_sent_at)->not->toBeNull();
});

test('a trial with 5 days left gets no reminder yet', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDays(5)]);

    Artisan::call('trials:send-reminders');

    expect($subscription->fresh()->trial_reminder_day5_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('a lapsed trial is expired and frozen exactly once', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->subDay()]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'created_at' => now()->subDays(5)]);
    StoreConnection::factory()->create(['team_id' => $team->id, 'status' => StoreConnection::STATUS_ACTIVE, 'created_at' => now()->subDays(4)]);
    $rule = Rule::factory()->create(['team_id' => $team->id, 'enabled' => true, 'created_by' => $owner->id]);

    Artisan::call('subscriptions:expire-trials');

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_EXPIRED);
    expect(StoreConnection::query()->where('team_id', $team->id)->where('status', StoreConnection::STATUS_PAUSED)->count())->toBe(1);
    expect($rule->fresh()->enabled)->toBeFalse();
});

test('a trial not yet lapsed is left untouched', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $subscription = Subscription::factory()->create(['team_id' => $team->id, 'status' => Subscription::STATUS_TRIAL, 'trial_ends_at' => now()->addDay()]);

    Artisan::call('subscriptions:expire-trials');

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_TRIAL);
});
