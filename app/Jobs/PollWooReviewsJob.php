<?php

namespace App\Jobs;

use App\Actions\Rules\CheckNegativeReviewAction;
use App\Models\Review;
use App\Models\StoreConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Polls the most recent reviews for one Woo connection (Plan §4.4's
 * `negative_review` trigger). Fetches only the latest 100 by date —
 * no cursor, so more than 100 new reviews between poll runs would miss
 * some (an honest limitation, not a silent one: reviews are rare enough
 * per store that this is unlikely to matter in practice). Idempotent on
 * `external_id`, and only a genuinely new row triggers rule evaluation.
 */
class PollWooReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function handle(CheckNegativeReviewAction $checkNegativeReview): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_WOO) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        $response = Http::withBasicAuth((string) $credentials['consumer_key'], (string) $credentials['consumer_secret'])
            ->get($credentials['store_url'].'/wp-json/wc/v3/products/reviews', [
                'per_page' => 100,
                'orderby' => 'date',
                'order' => 'desc',
            ]);

        if ($response->failed()) {
            return;
        }

        /** @var array<int, array<string, mixed>> $reviews */
        $reviews = (array) $response->json();

        foreach ($reviews as $raw) {
            $externalId = (string) $raw['id'];

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
                'product_title' => $raw['product_name'] ?? null,
                'rating' => (int) ($raw['rating'] ?? 0),
                'reviewer_name' => $raw['reviewer'] ?? null,
                'content' => $raw['review'] ?? null,
                'reviewed_at' => isset($raw['date_created']) ? Carbon::parse($raw['date_created']) : now(),
            ]);

            $checkNegativeReview->handle($review);
        }
    }
}
