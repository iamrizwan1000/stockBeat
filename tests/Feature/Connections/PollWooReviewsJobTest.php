<?php

use App\Jobs\PollWooReviewsJob;
use App\Models\Review;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function wooConnectionForReviewPolling(): StoreConnection
{
    $team = Team::factory()->create();

    return StoreConnection::query()->create([
        'team_id' => $team->id,
        'platform' => StoreConnection::PLATFORM_WOO,
        'name' => 'My Woo Store',
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => [
            'store_url' => 'https://example-shop.test',
            'consumer_key' => 'ck_x',
            'consumer_secret' => 'cs_x',
        ],
    ]);
}

test('the poller ingests new reviews idempotently', function () {
    $connection = wooConnectionForReviewPolling();

    Http::fake([
        '*/wp-json/wc/v3/products/reviews*' => Http::response([
            ['id' => 10, 'product_name' => 'Widget', 'rating' => 2, 'reviewer' => 'Sam', 'review' => 'Not great.', 'date_created' => '2026-01-01T00:00:00'],
        ], 200),
    ]);

    (new PollWooReviewsJob($connection->id))->handle(app(App\Actions\Rules\CheckNegativeReviewAction::class));
    (new PollWooReviewsJob($connection->id))->handle(app(App\Actions\Rules\CheckNegativeReviewAction::class));

    expect(Review::query()->where('connection_id', $connection->id)->count())->toBe(1);
    expect(Review::query()->first()->rating)->toBe(2);
});

test('the poller triggers a negative_review rule only for a genuinely new review', function () {
    $connection = wooConnectionForReviewPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_NEGATIVE_REVIEW,
        'controls' => ['negative_review_max_rating' => 3],
    ]);

    Http::fake([
        '*/wp-json/wc/v3/products/reviews*' => Http::response([
            ['id' => 11, 'product_name' => 'Widget', 'rating' => 1, 'reviewer' => 'Sam', 'review' => 'Terrible.', 'date_created' => '2026-01-01T00:00:00'],
        ], 200),
    ]);

    (new PollWooReviewsJob($connection->id))->handle(app(App\Actions\Rules\CheckNegativeReviewAction::class));
    (new PollWooReviewsJob($connection->id))->handle(app(App\Actions\Rules\CheckNegativeReviewAction::class));

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('polling a non-woo or missing connection is a safe no-op', function () {
    (new PollWooReviewsJob(999999))->handle(app(App\Actions\Rules\CheckNegativeReviewAction::class));
})->throwsNoExceptions();
