<?php

use App\Actions\Rules\RuleEvaluationAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\Rule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

test('a rule\'s configured sound reaches the FCM apns and android payload', function () {
    $order = Order::factory()->create(['total' => 100]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_NEW_ORDER,
        'actions' => [['type' => 'push']],
        'sound' => Rule::SOUND_CHA_CHING,
    ]);

    Device::factory()->create(['user_id' => $rule->created_by, 'push_token' => 'tok']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function ($message) {
            $payload = $message->jsonSerialize();

            return ($payload['apns']['payload']['aps']['sound'] ?? null) === 'cha_ching'
                && ($payload['android']['notification']['sound'] ?? null) === 'cha_ching';
        })
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->not->toBeNull();
    expect($execution->actions_result[0]['status'])->toBe('sent');
});

test('a rule with no configured sound leaves the FCM payload without an apns/android sound override', function () {
    $order = Order::factory()->create(['total' => 100]);
    $rule = Rule::factory()->create([
        'team_id' => $order->team_id,
        'trigger' => Rule::TRIGGER_NEW_ORDER,
        'actions' => [['type' => 'push']],
        'sound' => null,
    ]);

    Device::factory()->create(['user_id' => $rule->created_by, 'push_token' => 'tok']);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')
        ->once()
        ->withArgs(function ($message) {
            $payload = $message->jsonSerialize();

            return ! isset($payload['apns']['payload']['aps']['sound']) && ! isset($payload['android']['notification']['sound']);
        })
        ->andReturn([]);
    app()->instance(Messaging::class, $messaging);

    $execution = app(RuleEvaluationAction::class)->handle($rule, Rule::TRIGGER_NEW_ORDER, $order);

    expect($execution)->not->toBeNull();
    expect($execution->actions_result[0]['status'])->toBe('sent');
});
