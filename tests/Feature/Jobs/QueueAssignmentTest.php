<?php

use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Jobs\PollWooOrdersJob;
use App\Jobs\PollWooProductsJob;
use App\Jobs\PollWooReviewsJob;
use App\Jobs\ProcessWooWebhookJob;
use App\Jobs\RuleEvaluationJob;
use App\Jobs\SendBroadcastToRecipientJob;
use App\Models\Broadcast;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('rule evaluation and poll jobs are dispatched onto their named queues (Plan §15.1)', function () {
    Queue::fake();

    RuleEvaluationJob::dispatch(1, 'new_order');
    PollWooOrdersJob::dispatch(1);
    PollWooProductsJob::dispatch(1);
    PollWooReviewsJob::dispatch(1);

    Queue::assertPushedOn('rules', RuleEvaluationJob::class);
    Queue::assertPushedOn('poll', PollWooOrdersJob::class);
    Queue::assertPushedOn('poll', PollWooProductsJob::class);
    Queue::assertPushedOn('poll', PollWooReviewsJob::class);
});

test('WebhookController dispatches a verified woo webhook onto the ingest queue', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $user->id]);
    $connection = StoreConnection::factory()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'credentials' => ['webhook_secret' => 'shh', 'store_url' => 'https://example.test', 'consumer_key' => 'x', 'consumer_secret' => 'y'],
    ]);
    $payload = [
        'id' => 1, 'number' => '1', 'status' => 'processing', 'currency' => 'USD',
        'date_created_gmt' => '2026-07-16T00:00:00', 'total' => '10.00',
        'billing' => [], 'shipping' => [], 'line_items' => [],
    ];
    $signature = base64_encode(hash_hmac('sha256', json_encode($payload), 'shh', true));

    test()->withHeaders(['X-WC-Webhook-Topic' => 'order.created', 'X-WC-Webhook-Signature' => $signature])
        ->postJson("/hooks/woo/{$connection->id}", $payload)
        ->assertOk();

    Queue::assertPushedOn('ingest', ProcessWooWebhookJob::class);
});

test('broadcast recipient jobs route push/banner to notify-push and email to notify-email', function () {
    Queue::fake();

    SendBroadcastToRecipientJob::dispatch(1, 1, Broadcast::CHANNEL_PUSH)->onQueue('notify-push');
    SendBroadcastToRecipientJob::dispatch(1, 1, Broadcast::CHANNEL_EMAIL)->onQueue('notify-email');

    Queue::assertPushedOn('notify-push', SendBroadcastToRecipientJob::class);
    Queue::assertPushedOn('notify-email', SendBroadcastToRecipientJob::class);
});

test('per-store throttled jobs lock on a key unique to their connection', function () {
    $middlewareA = (new PollWooOrdersJob(1))->middleware();
    $middlewareB = (new PollWooOrdersJob(2))->middleware();

    expect($middlewareA[0])->toBeInstanceOf(WithoutOverlapping::class);

    // Two different connections must not share a lock key, or a busy store
    // would block an unrelated one from ever syncing.
    expect($middlewareA[0]->key)->not->toBe($middlewareB[0]->key);
});

test('the throttling trait is applied to every job that touches a single store connection', function () {
    foreach ([PollWooOrdersJob::class, PollWooProductsJob::class, PollWooReviewsJob::class, ProcessWooWebhookJob::class] as $jobClass) {
        expect(in_array(ThrottlesPerStoreConnection::class, class_uses_recursive($jobClass), true))->toBeTrue();
    }
});
