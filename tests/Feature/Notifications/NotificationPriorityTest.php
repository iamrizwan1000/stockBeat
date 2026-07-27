<?php

use App\Actions\Notifications\SendEmailNotificationAction;
use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Rule;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->seed(PlanSeeder::class);
});

test('a push notification defaults to normal priority when none is given', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    $notification = Notification::query()->where('user_id', $user->id)->firstOrFail();
    expect($notification->priority)->toBe(Notification::PRIORITY_NORMAL);
});

test('a critical priority push is stored and sent at the FCM/APNs immediate-delivery tier', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function (CloudMessage $message) {
            $payload = $message->jsonSerialize();

            return ($payload['android']['priority'] ?? null) === 'high'
                && ($payload['apns']['headers']['apns-priority'] ?? null) === '10';
        })
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body', priority: Notification::PRIORITY_CRITICAL);

    $notification = Notification::query()->where('user_id', $user->id)->firstOrFail();
    expect($notification->priority)->toBe(Notification::PRIORITY_CRITICAL);
});

test('a normal priority push is sent at the FCM/APNs power-conserving tier', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function (CloudMessage $message) {
            $payload = $message->jsonSerialize();

            return ($payload['android']['priority'] ?? null) === 'normal'
                && ($payload['apns']['headers']['apns-priority'] ?? null) === '5';
        })
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body', priority: Notification::PRIORITY_NORMAL);
});

test('an email notification stores the given priority', function () {
    $team = Team::factory()->create();
    $recipient = User::factory()->create();

    app(SendEmailNotificationAction::class)->handle($team, $recipient, 'Title', 'Body', priority: Notification::PRIORITY_HIGH);

    $notification = Notification::query()->where('user_id', $recipient->id)->firstOrFail();
    expect($notification->priority)->toBe(Notification::PRIORITY_HIGH);
});

test('a rule\'s priority defaults to normal and can be set to high/critical via the API', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['woo']])->assertOk();

    $response = test()->postJson('/api/v1/rules', [
        'name' => 'Critical high-value alert',
        'trigger' => Rule::TRIGGER_HIGH_VALUE_ORDER,
        'conditions' => ['all' => [['field' => 'total', 'operator' => 'gt', 'value' => 500]]],
        'actions' => [['type' => 'push']],
        'priority' => 'critical',
    ]);

    $response->assertCreated()->assertJsonPath('data.rule.priority', 'critical');
});

test('a rule\'s priority can be changed via PUT', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['woo']])->assertOk();

    $create = test()->postJson('/api/v1/rules', [
        'name' => 'New order alert',
        'trigger' => Rule::TRIGGER_NEW_ORDER,
        'actions' => [['type' => 'push']],
    ])->assertCreated();
    expect($create->json('data.rule.priority'))->toBe('normal');

    $id = $create->json('data.rule.id');
    test()->putJson("/api/v1/rules/{$id}", ['priority' => 'high'])
        ->assertOk()
        ->assertJsonPath('data.rule.priority', 'high');
});

test('an invalid rule priority is rejected', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    test()->postJson('/api/v1/profile/setup', ['name' => 'Jamie Seller', 'sells_on' => ['woo']])->assertOk();

    test()->postJson('/api/v1/rules', [
        'name' => 'Bad priority',
        'trigger' => Rule::TRIGGER_NEW_ORDER,
        'actions' => [['type' => 'push']],
        'priority' => 'urgent',
    ])->assertStatus(422)->assertJsonValidationErrors('priority');
});
