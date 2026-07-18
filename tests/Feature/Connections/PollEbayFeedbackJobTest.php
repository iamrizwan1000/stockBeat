<?php

use App\Actions\Rules\CheckNegativeReviewAction;
use App\Jobs\PollEbayFeedbackJob;
use App\Models\Review;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ebay.env' => 'sandbox']);
});

function ebayConnectionForFeedbackPolling(array $overrides = []): StoreConnection
{
    return StoreConnection::factory()->create(array_merge([
        'platform' => StoreConnection::PLATFORM_EBAY,
        'status' => StoreConnection::STATUS_ACTIVE,
        'credentials' => ['access_token' => 'fake-token', 'refresh_token' => 'fake-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
    ], $overrides));
}

function runEbayFeedbackPollJob(int $connectionId): void
{
    (new PollEbayFeedbackJob($connectionId))->handle(app(EbayAdapter::class), app(CheckNegativeReviewAction::class));
}

function ebayFeedbackResponseXml(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <GetFeedbackResponse xmlns="urn:ebay:apis:eBLBaseComponents">
        <Ack>Success</Ack>
        <FeedbackDetailArray>
            <FeedbackDetail>
                <FeedbackID>fb-1</FeedbackID>
                <CommentType>Negative</CommentType>
                <CommentingUser>disappointed_buyer</CommentingUser>
                <CommentText>Item arrived broken.</CommentText>
                <ItemID>110445566778</ItemID>
                <CommentTime>2026-07-18T10:00:00.000Z</CommentTime>
            </FeedbackDetail>
            <FeedbackDetail>
                <FeedbackID>fb-2</FeedbackID>
                <CommentType>Positive</CommentType>
                <CommentingUser>happy_buyer</CommentingUser>
                <CommentText>Great seller!</CommentText>
                <ItemID>110445566779</ItemID>
                <CommentTime>2026-07-18T10:00:00.000Z</CommentTime>
            </FeedbackDetail>
        </FeedbackDetailArray>
    </GetFeedbackResponse>
    XML;
}

test('the poller ingests only negative feedback as a review, idempotently', function () {
    $connection = ebayConnectionForFeedbackPolling();

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response(ebayFeedbackResponseXml(), 200)]);

    runEbayFeedbackPollJob($connection->id);
    runEbayFeedbackPollJob($connection->id);

    expect(Review::query()->where('connection_id', $connection->id)->count())->toBe(1);

    $review = Review::query()->where('connection_id', $connection->id)->first();
    expect($review->external_id)->toBe('fb-1');
    expect($review->rating)->toBe(1);
    expect($review->reviewer_name)->toBe('disappointed_buyer');
    expect($review->content)->toBe('Item arrived broken.');

    Http::assertSent(fn ($request) => $request->hasHeader('X-EBAY-API-CALL-NAME', 'GetFeedback'));
});

test('the poller triggers a negative_review rule for freshly ingested negative feedback', function () {
    $connection = ebayConnectionForFeedbackPolling();
    $rule = Rule::factory()->create([
        'team_id' => $connection->team_id,
        'trigger' => Rule::TRIGGER_NEGATIVE_REVIEW,
        'controls' => ['negative_review_max_rating' => 3],
    ]);

    Http::fake(['api.sandbox.ebay.com/ws/api.dll' => Http::response(ebayFeedbackResponseXml(), 200)]);

    runEbayFeedbackPollJob($connection->id);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('polling a non-ebay or missing connection is a safe no-op', function () {
    runEbayFeedbackPollJob(999999);
})->throwsNoExceptions();
