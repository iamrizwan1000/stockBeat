<?php

namespace App\Support\Rules;

use App\Models\Review;
use App\Models\Rule;

/**
 * Shared `controls.review_keyword` check (Plan §4.4, added 2026-07-27) — an
 * optional extra filter on top of a review-rating trigger's threshold, used
 * identically by `CheckNegativeReviewAction` and `CheckPositiveReviewAction`
 * so a rule can narrow to e.g. only low ratings that also mention "broken"
 * rather than every low rating. Case-insensitive substring match against
 * the review's `content`, same matching style as `ConditionEvaluator`'s
 * `sku`/`product` conditions. Absent/blank keyword always matches — it's an
 * optional narrowing filter, not a requirement.
 */
class ReviewKeywordFilter
{
    public static function matches(Rule $rule, Review $review): bool
    {
        $keyword = $rule->controls['review_keyword'] ?? null;

        if (! is_string($keyword) || trim($keyword) === '') {
            return true;
        }

        return str_contains(strtolower((string) $review->content), strtolower($keyword));
    }
}
