<?php

namespace App\Jobs;

use App\Actions\Rules\CheckLowStockAction;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Product;
use App\Models\StoreConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Polls the full product catalog for one Woo connection (Plan §4.4's
 * `low_stock` trigger). Unlike orders there's no `modified_after`
 * cursor here — the whole catalog is re-fetched every run, which is
 * correct but doesn't scale to very large catalogs; that's an acceptable
 * trade for the MVP since re-fetching is idempotent either way.
 */
class PollWooProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(CheckLowStockAction $checkLowStock): void
    {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_WOO) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = Http::withBasicAuth((string) $credentials['consumer_key'], (string) $credentials['consumer_secret'])
                ->get($credentials['store_url'].'/wp-json/wc/v3/products', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                // Transient failure — the next scheduled run retries.
                return;
            }

            /** @var array<int, array<string, mixed>> $products */
            $products = (array) $response->json();

            foreach ($products as $raw) {
                $sku = (string) ($raw['sku'] ?? '');
                $managesStock = (bool) ($raw['manage_stock'] ?? false);

                $product = Product::query()->updateOrCreate(
                    ['connection_id' => $connection->id, 'external_id' => (string) $raw['id']],
                    [
                        'team_id' => $connection->team_id,
                        'sku' => $sku !== '' ? $sku : null,
                        'title' => (string) ($raw['name'] ?? ''),
                        'stock_quantity' => $managesStock ? $raw['stock_quantity'] : null,
                    ],
                );

                $checkLowStock->handle($product);
            }

            $totalPagesHeader = $response->header('X-WP-TotalPages');
            $totalPages = $totalPagesHeader !== '' ? (int) $totalPagesHeader : 1;
            $page++;
        } while ($page <= $totalPages);
    }
}
