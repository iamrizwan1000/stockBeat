<?php

namespace App\Actions\Rules;

use App\Models\Review;
use App\Models\Rule;
use Illuminate\Support\Str;

/**
 * Evaluates the `negative_review` trigger (Plan §4.4) for one newly-ingested
 * review against every enabled negative_review rule on its team. Order-less
 * like `digest`/`low_stock` — dedup here is simply "only ever called for a
 * genuinely new review row" (the poller only calls this once, at insert
 * time), so no notified-at bookkeeping is needed the way `low_stock` needs
 * it for its fluctuating stock value.
 */
class CheckNegativeReviewAction
{
    public function __construct(
        private readonly RuleEvaluationAction $evaluation,
    ) {}

    public function handle(Review $review): void
    {
        $rules = Rule::query()
            ->where('team_id', $review->team_id)
            ->where('trigger', Rule::TRIGGER_NEGATIVE_REVIEW)
            ->where('enabled', true)
            ->get();

        foreach ($rules as $rule) {
            $maxRating = (int) ($rule->controls['negative_review_max_rating'] ?? 3);

            if ($review->rating > $maxRating) {
                continue;
            }

            $this->evaluation->handle($rule, Rule::TRIGGER_NEGATIVE_REVIEW, null, [
                'rating' => $review->rating,
                'product_title' => $review->product_title,
                'excerpt' => Str::limit(strip_tags((string) $review->content), 140),
            ]);
        }
    }
}
