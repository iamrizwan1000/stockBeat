<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ResolveEntitlementsAction;
use App\Actions\Reviews\ReplyToReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ReplyToReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Reviews
 *
 * Polled customer reviews (Plan §4.4's `negative_review` trigger) — the
 * only seller-actionable field is a reply, per §4.15, and only on channels
 * where `capabilities()->reviewReply` is true (eBay only in v1).
 *
 * **Requires `inbox_enabled` (Pro+, Plan §4.15)** — reviewing/replying to a
 * customer is the same underlying capability as the Unified Inbox, so it's
 * gated behind the same flag rather than its own separate one.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ResolveEntitlementsAction $resolveEntitlements,
    ) {}

    /**
     * List this team's polled reviews, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam();

        if ($team === null) {
            return ApiResponse::success(['reviews' => []]);
        }

        if (! $this->inboxEnabled($team)) {
            return ApiResponse::error('Reviews requires the Pro plan or higher.', status: 403);
        }

        $reviews = Review::query()->where('team_id', $team->id)->orderByDesc('reviewed_at')->get();

        return ApiResponse::success(['reviews' => ReviewResource::collection($reviews)]);
    }

    /**
     * Reply to a review on its platform.
     *
     * @response 200 scenario="success" {
     *   "success": true,
     *   "message": "Reply sent.",
     *   "data": { "review": { "id": 1, "connection_id": 1, "rating": 1, "content": "Item arrived late." } }
     * }
     * @response 422 scenario="capability not supported" {
     *   "success": false,
     *   "message": "The given data was invalid.",
     *   "errors": { "review": ["This channel doesn't support replying to reviews from here."] }
     * }
     */
    public function reply(ReplyToReviewRequest $request, Review $review, ReplyToReviewAction $action): JsonResponse
    {
        $this->authorizeReviewAccess($request, $review);

        $result = $action->handle($review, $request->string('body')->toString());

        if (! $result->success) {
            return ApiResponse::error($result->message);
        }

        return ApiResponse::success(['review' => new ReviewResource($review)], $result->message);
    }

    private function authorizeReviewAccess(Request $request, Review $review): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($review->team_id !== $user->currentTeam()?->id) {
            abort(404);
        }

        if (! $this->inboxEnabled($review->team)) {
            abort(403, 'Reviews requires the Pro plan or higher.');
        }
    }

    private function inboxEnabled(Team $team): bool
    {
        return (bool) ($this->resolveEntitlements->handle($team)['limits']['inbox_enabled'] ?? false);
    }
}
