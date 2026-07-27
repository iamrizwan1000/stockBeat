<?php

namespace App\Actions\Rules;

use App\Models\Review;
use App\Models\Rule;
use App\Support\Rules\ReviewKeywordFilter;
use Illuminate\Support\Str;

/**
 * Evaluates the `positive_review` trigger (Plan §4.4, added 2026-07-27) —
 * the ratings-scale mirror of `CheckNegativeReviewAction`, called from the
 * same call site (the review poller, once per genuinely new review row), so
 * no separate dedup bookkeeping is needed here either.
 * `controls.positive_review_min_rating` defaults to 5 (only a perfect
 * rating counts as "positive" unless the seller widens it), and
 * `controls.review_keyword` (`ReviewKeywordFilter`) applies identically to
 * `CheckNegativeReviewAction`.
 *
 * **Honest platform scope:** only wired into `PollWooReviewsJob`, not
 * `PollEbayFeedbackJob` — eBay's feedback poller specifically calls the
 * Trading API's negative-feedback filter (`fetchNegativeFeedback()`), so it
 * never ingests a positive review to check in the first place. Wiring this
 * action there would be dead code implying a capability eBay's own poller
 * can't supply.
 */
class CheckPositiveReviewAction
{
    public function __construct(
        private readonly RuleEvaluationAction $evaluation,
    ) {}

    public function handle(Review $review): void
    {
        $rules = Rule::query()
            ->where('team_id', $review->team_id)
            ->where('trigger', Rule::TRIGGER_POSITIVE_REVIEW)
            ->where('enabled', true)
            ->get();

        foreach ($rules as $rule) {
            $minRating = (int) ($rule->controls['positive_review_min_rating'] ?? 5);

            if ($review->rating < $minRating) {
                continue;
            }

            if (! ReviewKeywordFilter::matches($rule, $review)) {
                continue;
            }

            $this->evaluation->handle($rule, Rule::TRIGGER_POSITIVE_REVIEW, null, [
                'rating' => $review->rating,
                'product_title' => $review->product_title,
                'excerpt' => Str::limit(strip_tags((string) $review->content), 140),
                'connection_id' => $review->connection_id,
            ]);
        }
    }
}
