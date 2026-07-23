<?php

use App\Actions\Rules\CheckNegativeReviewAction;
use App\Models\Review;
use App\Models\Rule;
use App\Models\RuleExecution;
use App\Models\StoreConnection;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a review at or below the max rating fires the negative_review rule', function () {
    $team = Team::factory()->create();
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_NEGATIVE_REVIEW,
        'controls' => ['negative_review_max_rating' => 3],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'rating' => 2]);

    app(CheckNegativeReviewAction::class)->handle($review);

    expect(RuleExecution::query()->where('rule_id', $rule->id)->count())->toBe(1);
});

test('a review above the max rating does not fire', function () {
    $team = Team::factory()->create();
    Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_NEGATIVE_REVIEW,
        'controls' => ['negative_review_max_rating' => 3],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'rating' => 5]);

    app(CheckNegativeReviewAction::class)->handle($review);

    expect(RuleExecution::query()->count())->toBe(0);
});

test('the review\'s store connection is passed through and mutes the push when muted', function () {
    $team = Team::factory()->create();
    $connection = StoreConnection::factory()->create(['team_id' => $team->id, 'notifications_muted' => true]);
    $rule = Rule::factory()->create([
        'team_id' => $team->id,
        'trigger' => Rule::TRIGGER_NEGATIVE_REVIEW,
        'controls' => ['negative_review_max_rating' => 3],
    ]);
    $review = Review::factory()->create(['team_id' => $team->id, 'connection_id' => $connection->id, 'rating' => 2]);

    app(CheckNegativeReviewAction::class)->handle($review);

    $execution = RuleExecution::query()->where('rule_id', $rule->id)->firstOrFail();
    expect($execution->actions_result[0])->toMatchArray(['type' => 'push', 'status' => 'muted_by_store']);
});
