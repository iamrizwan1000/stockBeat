<?php

use App\Actions\Notifications\SendPushNotificationAction;
use App\Models\Device;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;

uses(RefreshDatabase::class);

test('a user with no devices gets no_devices but is still logged to the notification center', function () {
    $user = User::factory()->create();

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('no_devices');
    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('a successful send reports sent and keeps the device', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('sent');
    expect(Device::query()->find($device->id))->not->toBeNull();
});

test('an unregistered token prunes the device', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'push_token' => 'dead-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andThrow(NotFound::becauseTokenNotFound('dead-token'));
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('failed');
    expect(Device::query()->find($device->id))->toBeNull();
});

test('a non-NotFound messaging failure leaves the device intact', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'push_token' => 'some-token']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andThrow(new InvalidMessage('bad message'));
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('failed');
    expect(Device::query()->find($device->id))->not->toBeNull();
});

test('push is muted when the user has push disabled, but still logged to the notification center', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);
    NotificationPreference::factory()->create(['user_id' => $user->id, 'push_enabled' => false]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('muted_by_preference');
    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('a notification with no explicit sound falls back to the recipient\'s saved sound preference', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);
    NotificationPreference::factory()->create(['user_id' => $user->id, 'sound' => 'cha_ching']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function ($message) {
            $payload = $message->jsonSerialize();

            return $payload['apns']['payload']['aps']['sound'] === 'cha_ching'
                && $payload['android']['notification']['sound'] === 'cha_ching';
        }))
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('sent');
});

test('an explicit rule sound wins over the recipient\'s saved sound preference', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);
    NotificationPreference::factory()->create(['user_id' => $user->id, 'sound' => 'cha_ching']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function ($message) {
            $payload = $message->jsonSerialize();

            return $payload['apns']['payload']['aps']['sound'] === 'alert'
                && $payload['android']['notification']['sound'] === 'alert';
        }))
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body', sound: 'alert');

    expect($status)->toBe('sent');
});

test('push is suppressed during the user\'s personal quiet hours', function () {
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id, 'push_token' => 'valid-token']);
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'quiet_hours_start' => '00:00',
        'quiet_hours_end' => '23:59',
        'quiet_hours_timezone' => 'UTC',
    ]);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');
    app()->instance(Messaging::class, $messaging);

    $status = app(SendPushNotificationAction::class)->handle($user, 'Title', 'Body');

    expect($status)->toBe('quiet_hours');
});
