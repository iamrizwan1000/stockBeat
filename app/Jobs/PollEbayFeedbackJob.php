<?php

namespace App\Jobs;

use App\Actions\Rules\CheckNegativeReviewAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Review;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Polls the Trading API for negative buyer feedback on one eBay connection
 * (Plan §4.4's `negative_review` trigger, §7.3: "poll feedback via Trading
 * API for negative-feedback alerts"). Same "fetch the latest page, no
 * cursor, dedupe on external_id" shape as `PollWooReviewsJob` — the two
 * platforms feed the identical `reviews` pipeline. Proactively refreshes an
 * about-to-expire access token first, same as `PollEbayMessagesJob`.
 */
class PollEbayFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(EbayAdapter $adapter, CheckNegativeReviewAction $checkNegativeReview): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_EBAY) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            $adapter->refreshAuth($connection);
            $connection = $connection->fresh();

            if ($connection === null || $connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
                return;
            }
        }

        foreach ($adapter->fetchNegativeFeedback($connection) as $raw) {
            $externalId = $raw['external_id'];

            $exists = Review::query()
                ->where('connection_id', $connection->id)
                ->where('external_id', $externalId)
                ->exists();

            if ($exists) {
                continue;
            }

            $review = Review::query()->create([
                'team_id' => $connection->team_id,
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'product_title' => $raw['item_id'],
                'rating' => $raw['rating'],
                'reviewer_name' => $raw['reviewer_name'],
                'content' => $raw['content'],
                'reviewed_at' => $raw['created_at'],
            ]);

            $checkNegativeReview->handle($review);
        }
    }
}
