<?php

namespace App\Jobs;

use App\Actions\Rules\CheckBackInStockAction;
use App\Actions\Rules\CheckLowStockAction;
use App\Actions\Rules\CheckStaleInventoryAction;
use App\Jobs\Concerns\PaginatesShopifyLinkHeader;
use App\Jobs\Concerns\ThrottlesPerStoreConnection;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\StoreConnection;
use App\Support\Connections\Adapters\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shopify had no product-catalog sync at all until this — only a reactive
 * `inventory_levels/update` webhook handler (`ShopifyAdapter::
 * syncInventoryLevel()`), which only ever touches a product AFTER a stock
 * change happens post-connect. A freshly connected store showed zero
 * products until then, with no periodic safety net either (unlike
 * `PollWooProductsJob`, which this mirrors). Same variant→Product row
 * shape and title-resolution convention as `syncInventoryLevel()` — a
 * multi-variant product's rows all carry the parent product's title, not
 * a per-variant one, matching that existing behavior exactly rather than
 * introducing a second convention.
 */
class PollShopifyProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, PaginatesShopifyLinkHeader, Queueable, SerializesModels, ThrottlesPerStoreConnection;

    private const API_VERSION = '2026-07';

    /** Same reasoning as PollShopifyOrdersJob's own cap. */
    private const MAX_PAGES = 20;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('poll');
    }

    public function handle(
        CheckLowStockAction $checkLowStock,
        CheckStaleInventoryAction $checkStaleInventory,
        CheckBackInStockAction $checkBackInStock,
        ShopifyAdapter $adapter,
    ): void {
        $connection = StoreConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->platform !== StoreConnection::PLATFORM_SHOPIFY) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        // Same silent-refresh-before-polling pattern as PollShopifyOrdersJob
        // — this job hits the same Admin API with the same token, so it's
        // subject to the identical expiry/reauth handling.
        if ($expiresAt === null || $expiresAt->isPast()) {
            $adapter->refreshAuth($connection);
            $connection = $connection->fresh();

            if ($connection === null || $connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
                return;
            }
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $shop = (string) ($credentials['shop_domain'] ?? '');
        $token = (string) ($credentials['access_token'] ?? '');

        $client = Http::baseUrl("https://{$shop}/admin/api/".self::API_VERSION)
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson();

        $path = '/products.json';
        // Without `status`, Shopify returns products of every status —
        // active, archived, AND draft (unpublished, not yet sellable) —
        // which would put meaningless low-stock/back-in-stock alerts on
        // something the merchant hasn't even launched yet.
        $query = ['limit' => 100, 'status' => 'active'];
        $page = 0;

        do {
            $response = $query === null ? $client->get($path) : $client->get($path, $query);
            $page++;

            if ($response->failed()) {
                // Same tolerance as the orders poller: a later page failing
                // mid-catalog keeps whatever was already ingested this run
                // rather than discarding it; the next scheduled run retries
                // from the top (no cursor to resume from, same as Woo's
                // full-recatalog-every-run approach).
                Log::warning('Shopify product poll failed', [
                    'connection_id' => $connection->id,
                    'shop' => $shop,
                    'page' => $page,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($page === 1) {
                    return;
                }

                break;
            }

            /** @var array<int, array<string, mixed>> $products */
            $products = (array) $response->json('products', []);

            foreach ($products as $rawProduct) {
                $this->syncProduct($connection, $rawProduct, $checkLowStock, $checkStaleInventory, $checkBackInStock);
            }

            $path = $this->nextPageUrl($response);
            $query = null;
        } while ($path !== null && $page < self::MAX_PAGES);

        if ($path !== null) {
            Log::warning('Shopify product poll: hit the per-run page cap with more pages remaining', [
                'connection_id' => $connection->id,
                'pages_fetched' => $page,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $rawProduct
     */
    private function syncProduct(
        StoreConnection $connection,
        array $rawProduct,
        CheckLowStockAction $checkLowStock,
        CheckStaleInventoryAction $checkStaleInventory,
        CheckBackInStockAction $checkBackInStock,
    ): void {
        $title = (string) ($rawProduct['title'] ?? '');
        $mainImageUrl = $rawProduct['image']['src'] ?? null;

        /** @var array<int, array<string, mixed>> $productImages */
        $productImages = (array) ($rawProduct['images'] ?? []);

        /** @var array<int, array<string, mixed>> $variants */
        $variants = (array) ($rawProduct['variants'] ?? []);

        foreach ($variants as $variant) {
            if (! isset($variant['id'])) {
                continue;
            }

            // `inventory_management` is null for a variant Shopify isn't
            // tracking stock for at all (e.g. a service/digital item) —
            // `inventory_quantity` isn't meaningful there, same gate as
            // Woo's `manage_stock` boolean.
            $managesStock = ($variant['inventory_management'] ?? null) === 'shopify';

            // A variant with its own assigned image (e.g. a specific color
            // swatch) shows that one; every other variant falls back to the
            // product's main image — most sellers only ever set one image
            // for the whole product, not per variant.
            $imageUrl = $mainImageUrl;
            $variantImageId = $variant['image_id'] ?? null;

            if ($variantImageId !== null) {
                $variantImage = collect($productImages)->firstWhere('id', $variantImageId);
                $imageUrl = $variantImage['src'] ?? $mainImageUrl;
            }

            $product = Product::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => (string) $variant['id']],
                [
                    'team_id' => $connection->team_id,
                    'sku' => ($variant['sku'] ?? null) !== '' ? ($variant['sku'] ?? null) : null,
                    'title' => $title !== '' ? $title : 'Unknown product',
                    'image_url' => $imageUrl,
                    'stock_quantity' => $managesStock ? $variant['inventory_quantity'] ?? null : null,
                ],
            );

            if ($product->stock_quantity !== null) {
                ProductStockSnapshot::query()->create([
                    'product_id' => $product->id,
                    'stock_quantity' => $product->stock_quantity,
                    'recorded_at' => now(),
                ]);
            }

            $checkLowStock->handle($product);
            $checkStaleInventory->handle($product);
            $checkBackInStock->handle($product);
        }
    }
}
