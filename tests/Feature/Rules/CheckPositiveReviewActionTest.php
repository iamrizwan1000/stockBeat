<?php

use App\Actions\Rules\CheckPositiveReviewAction;
use App\Models\Review;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a review at or above the min rating fires the positive_review rule', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_POSITIVE_REVIEW,
        'controls' => ['positive_review_min_rating' => 5],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'rating' => 5]);

    app(CheckPositiveReviewAction::class)->handle($review);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('a review below the min rating does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_POSITIVE_REVIEW,
        'controls' => ['positive_review_min_rating' => 5],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'rating' => 4]);

    app(CheckPositiveReviewAction::class)->handle($review);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('defaults to requiring a perfect 5-star rating when positive_review_min_rating is not set', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create(['team_id' => $team->id, 'trigger' => Rule::TRIGGER_POSITIVE_REVIEW, 'controls' => null]);
    $fourStar = Review::factory()->create(['team_id' => $team->id, 'rating' => 4]);
    $fiveStar = Review::factory()->create(['team_id' => $team->id, 'rating' => 5]);

    app(CheckPositiveReviewAction::class)->handle($fourStar);
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(0);

    app(CheckPositiveReviewAction::class)->handle($fiveStar);
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('the review\'s store connection is passed through and mutes the push when muted', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_POSITIVE_REVIEW,
        'controls' => ['positive_review_min_rating' => 5],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'rating' => 5]);

    app(CheckPositiveReviewAction::class)->handle($review);

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});

test('review_keyword narrows a positive_review rule to reviews mentioning that word', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_POSITIVE_REVIEW,
        'controls' => ['positive_review_min_rating' => 5, 'review_keyword' => 'fast shipping'],
    ]);

    $matching = Review::factory()->create(['team_id' => $team->id, 'rating' => 5, 'content' => 'Loved it! Fast Shipping too.']);
    app(CheckPositiveReviewAction::class)->handle($matching);
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);

    $nonMatching = Review::factory()->create(['team_id' => $team->id, 'rating' => 5, 'content' => 'Great product overall.']);
    app(CheckPositiveReviewAction::class)->handle($nonMatching);
    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});
