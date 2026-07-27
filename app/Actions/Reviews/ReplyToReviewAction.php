<?php

namespace App\Actions\Reviews;

use App\Models\Review;
use App\Support\Connections\ActionResult;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Validation\ValidationException;

/**
 * Replies to a customer review on its platform (Plan §4.15), delegated to
 * the review's `ChannelAdapter` — server-enforced against `capabilities()`
 * rather than trusting that the mobile app only shows the control when
 * supported, same convention as `UpdateProductStockAction`.
 */
class ReplyToReviewAction
{
    public function __construct(
        private readonly ChannelAdapterManager $adapters,
    ) {}

    public function handle(Review $review, string $body): ActionResult
    {
        $adapter = $this->adapters->driver($review->connection->platform);

        if (! $adapter->capabilities()->reviewReply) {
            throw ValidationException::withMessages([
                'review' => 'This channel doesn\'t support replying to reviews from here.',
            ]);
        }

        $result = $adapter->replyToReview($review, $body);

        if ($result->success) {
            $review->update(['replied_at' => now()]);
        }

        return $result;
    }
}
