<?php

namespace App\Jobs;

use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\EbayAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Polls eBay's Sell Inventory API for current stock levels on one
 * connection (Plan §4.4's `low_stock` trigger, §7.8's eBay "Inventory
 * update: ✅"). Same shape as `PollWooProductsJob`, feeding the identical
 * `products` pipeline `CheckLowStockAction` reads from. No inventory
 * webhook exists for eBay (same v1 "polling only" scope cut as order sync,
 * see `EbayAdapter`'s class docblock) — proactively refreshes an
 * about-to-expire access token first, same as `PollEbayMessagesJob`/
 * `PollEbayFeedbackJob`.
 */
class PollEbayInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(EbayAdapter $adapter, CheckLowStockAction $checkLowStock): void
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

        foreach ($adapter->fetchInventoryItems($connection) as $raw) {
            $product = Product::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $raw['external_id']],
                [
                    'team_id' => $connection->team_id,
                    'sku' => $raw['sku'],
                    'title' => $raw['title'],
                    'stock_quantity' => $raw['stock_quantity'],
                ],
            );

            $checkLowStock->handle($product);
        }
    }
}
