<?php

namespace App\Jobs;

use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Payout;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Polls Shopify Payments' Payouts API for one connection (Plan §4.14) — no
 * payout webhook exists, so this is poll-only, same convention as
 * `PollEbayInventoryJob`/`PollWooProductsJob` for their own trigger data.
 */
class PollShopifyPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(ShopifyAdapter $adapter): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_SHOPIFY) {
            return;
        }

        foreach ($adapter->fetchPayouts($connection) as $raw) {
            Payout::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $raw['external_id']],
                [
                    'team_id' => $connection->team_id,
                    'amount' => $raw['amount'],
                    'currency' => $raw['currency'],
                    'status' => $raw['status'],
                    'arrival_date' => $raw['arrival_date'],
                    'raw' => $raw['raw'],
                ],
            );
        }
    }
}
