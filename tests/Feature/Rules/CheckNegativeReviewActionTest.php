<?php

use App\Actions\Rules\CheckNegativeReviewAction;
use App\Models\Review;
use App\Models\Rule;
use App\Models\RuleExecution;
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
