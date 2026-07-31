<?php

namespace App\Support\Connections\Adapters;

use App\Actions\Connections\ComputeStoreConnectionFingerprintAction;
use App\Contracts\ChannelAdapter;
use App\Contracts\OAuthChannelAdapter;
use App\Exceptions\Connections\AdapterNotReadyException;
use App\Jobs\PollShopifyOrdersJob;
use App\Jobs\PollShopifyProductsJob;
use App\Jobs\RuleEvaluationJob;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Connections\ActionResult;
use App\Support\Connections\Adapters\Shopify\ShopifyOrderMapper;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use App\Support\Orders\NormalizedOrder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Real Shopify adapter (Plan §7.1) — OAuth 2.0 authorization-code flow via
 * a Partner-registered public (unlisted) app. Unlike WooCommerce's direct
 * key intake, connecting is a two-step round trip through Shopify's own
 * authorization page (`OAuthChannelAdapter`), driven by
 * `StartOAuthConnectionAction`/`OAuthCallbackController`.
 */
class ShopifyAdapter implements ChannelAdapter, OAuthChannelAdapter
{
    private const API_VERSION = '2026-07';

    private const SCOPES = 'read_orders,write_orders,read_fulfillments,write_fulfillments,read_products,write_products,read_inventory,write_inventory,read_locations,read_customers';

    /**
     * `orders/fulfilled` and `refunds/create` are real, distinct Shopify
     * webhook topics — not implied by `orders/updated` (§7.1's own list).
     * `inventory_levels/update` feeds the `low_stock` trigger (§4.4/§7.1) —
     * real-time, unlike Woo's `products:poll-woo` poller.
     */
    private const WEBHOOK_TOPICS = ['orders/create', 'orders/updated', 'orders/cancelled', 'orders/fulfilled', 'refunds/create', 'inventory_levels/update', 'app/uninstalled'];

    public function __construct(
        private readonly ShopifyOrderMapper $orderMapper,
        private readonly ComputeStoreConnectionFingerprintAction $fingerprintAction,
    ) {}

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $this->assertConfigured();

        $shopDomain = (string) ($startCredentials['shop_domain'] ?? '');

        // `expiring=1` does NOT belong here — it's a token-exchange (POST
        // /admin/oauth/access_token) body parameter, not an /authorize
        // query param (confirmed against shopify.dev's own example
        // request; an earlier version of this method incorrectly put it
        // here, where Shopify just silently ignores it as an unrecognized
        // param). See completeConnection() for where it actually goes.
        return "https://{$shopDomain}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => self::SCOPES,
            'redirect_uri' => route('hooks.shopify.oauth-callback'),
            'state' => $state,
        ]);
    }

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function completeConnection(Team $team, string $name, array $startCredentials, string $nonce, Request $callback): StoreConnection
    {
        $this->assertConfigured();

        if (! $this->hasValidQueryHmac($callback)) {
            throw ValidationException::withMessages(['shopify' => 'Invalid callback signature.']);
        }

        $shop = (string) $callback->query('shop', '');
        $code = (string) $callback->query('code', '');
        $expectedShop = (string) ($startCredentials['shop_domain'] ?? '');

        if ($shop === '' || $code === '' || $shop !== $expectedShop) {
            throw ValidationException::withMessages(['shopify' => 'Shop mismatch or missing authorization code.']);
        }

        $tokenResponse = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
            // The actual, verbatim-confirmed location of this param
            // (shopify.dev/docs/apps/build/authentication-authorization/
            // access-tokens/offline-access-tokens's own example curl
            // request) — without it Shopify issues the old non-expiring
            // token, which the Admin API now rejects outright for this
            // app. Public apps created after 2026-04-01 are required to
            // use expiring tokens outright, so this isn't optional.
            'expiring' => '1',
        ]);

        $accessToken = $tokenResponse->json('access_token');

        if ($tokenResponse->failed() || ! is_string($accessToken)) {
            throw ValidationException::withMessages(['shopify' => 'Could not complete the Shopify connection.']);
        }

        // `expiring=1` on the authorization URL above means this response
        // carries `expires_in` (~1hr) and `refresh_token` (itself expiring
        // after `refresh_token_expires_in`, ~90 days of inactivity) —
        // `refreshAuth()` uses the refresh token to get a new access token
        // without the merchant ever seeing a reauth prompt, same shape as
        // EbayAdapter. Both are absent for a connection made before this
        // shipped, or if a future app config somehow reverts to the old
        // flow — treated as "not expiring" rather than guessed, same
        // fallback as the pre-`expiring=1` behavior.
        $expiresIn = $tokenResponse->json('expires_in');
        $expiresAt = is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn)->toIso8601String() : null;
        $refreshToken = $tokenResponse->json('refresh_token');

        // Ground truth for diagnosing "reconnected, immediately needs
        // reauth again" — if `expiring=1` isn't actually landing an
        // expiring token (e.g. this Partner app needs an explicit opt-in
        // in the Partner Dashboard before the parameter takes effect),
        // this is where that shows up: `expires_in` present tells you the
        // exchange itself is fine either way. Never logs the token values
        // themselves.
        Log::info('Shopify token exchange completed', [
            'shop' => $shop,
            'has_expires_in' => is_numeric($expiresIn),
            'has_refresh_token' => is_string($refreshToken),
            'scope' => $tokenResponse->json('scope'),
        ]);

        $credentials = [
            'shop_domain' => $shop,
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) ? $refreshToken : null,
            'expires_at' => $expiresAt,
        ];

        // `OAuthCallbackController` only short-circuits (skips calling this
        // method at all) for an already-ACTIVE fingerprint match — a
        // `needs_reauth` connection reaches here on a genuine reconnect
        // attempt. Without this lookup, that reconnect would silently
        // create a second row while leaving the original stuck exactly as
        // it was: still `needs_reauth`, still holding the dead token,
        // still failing every poll — the merchant taps "Reconnect", sees a
        // success message, and the connection list never changes. Reusing
        // the same row instead preserves its id and everything hanging off
        // it (orders, rules, notification history).
        $fingerprint = $this->fingerprintAction->handle(StoreConnection::PLATFORM_SHOPIFY, $credentials);

        $existing = $fingerprint === null ? null : StoreConnection::query()
            ->where('team_id', $team->id)
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', StoreConnection::STATUS_DISCONNECTED)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'name' => $name,
                'credentials' => $credentials,
                'status' => StoreConnection::STATUS_ACTIVE,
            ]);
            $connection = $existing;
        } else {
            $connection = StoreConnection::query()->create([
                'team_id' => $team->id,
                'platform' => StoreConnection::PLATFORM_SHOPIFY,
                'name' => $name,
                'credentials' => $credentials,
                'fingerprint' => $fingerprint,
                'status' => StoreConnection::STATUS_ACTIVE,
            ]);
        }

        $this->fetchStoreBranding($connection);
        $this->registerWebhooks($connection);

        // See WooCommerceAdapter::connect()'s equivalent comment — don't
        // make the merchant wait for the next scheduled poll tick for
        // their first sync. Safe: per-connection overlap lock +
        // IngestOrderAction's idempotent upsert prevent any double-processing
        // against webhooks or the scheduled poller.
        PollShopifyOrdersJob::dispatch($connection->id);

        // Same reasoning — without this a freshly connected store shows
        // zero products until either the next scheduled products:poll-
        // shopify tick (up to 30 min) or an inventory_levels/update
        // webhook happens to fire first.
        PollShopifyProductsJob::dispatch($connection->id);

        return $connection;
    }

    /**
     * Pulls the shop's own contact email/name (Plan §7.7's inbox branding)
     * straight from the Admin API instead of asking the merchant to type it
     * in — keeps the connect flow a single OAuth round trip. Best-effort:
     * a failure here never blocks the connection itself, same tolerance as
     * `registerWebhooks()`.
     */
    private function fetchStoreBranding(StoreConnection $connection): void
    {
        $response = $this->http($connection)->get('/shop.json');

        if ($response->failed()) {
            return;
        }

        $connection->update([
            'store_contact_email' => $response->json('shop.email'),
            'store_display_name' => $response->json('shop.name'),
        ]);
    }

    /**
     * Not used by this adapter's own flow — Shopify only ever connects via
     * the two-step OAuth flow above (`OAuthChannelAdapter`), which
     * `ConnectionController` routes to instead of this `ChannelAdapter`
     * method. Reachable only if that routing check were bypassed, which
     * would itself be the bug worth surfacing loudly here.
     */
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw new LogicException('ShopifyAdapter connects via OAuth — use StartOAuthConnectionAction, not connect().');
    }

    /**
     * Expiring offline tokens (`expiring=1` at authorization, see
     * `authorizationUrl()`) refresh the same way eBay's do — a
     * `grant_type=refresh_token` round trip to the same token endpoint,
     * silently, with no merchant-visible reauth. Shopify rotates the
     * refresh token on every use ("Shopify returns a new access token and
     * a new refresh token"), so the old one is discarded here, not reused.
     *
     * A connection with no `refresh_token` at all — made before this
     * shipped, back when Shopify still issued non-expiring tokens by
     * default — has nothing to refresh with; that's a real dead end, not
     * a bug, and routes to `needs_reauth` so the merchant reconnects once
     * and gets a refreshable token from then on.
     */
    public function refreshAuth(StoreConnection $connection): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $shop = (string) ($credentials['shop_domain'] ?? '');

        if ($refreshToken === '' || $shop === '') {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        $accessToken = $response->json('access_token');
        $newRefreshToken = $response->json('refresh_token');

        if ($response->failed() || ! is_string($accessToken) || ! is_string($newRefreshToken)) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        $connection->update([
            'credentials' => [
                ...$credentials,
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resilient per-topic, same convention as WooCommerceAdapter — a
     * partial failure still leaves the other topics registered.
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $callbackUrl = route('hooks.shopify', ['connection' => $connection->id]);
        $registered = [];
        $failedAny = false;

        foreach (self::WEBHOOK_TOPICS as $topic) {
            $response = $this->http($connection)->post('/webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $callbackUrl,
                    'format' => 'json',
                ],
            ]);

            if ($response->successful()) {
                $registered[$topic] = $response->json('webhook.id');
            } else {
                $failedAny = true;
            }
        }

        $connection->update([
            'credentials' => [...$credentials, 'webhook_ids' => $registered],
            'webhook_status' => $failedAny ? 'partial' : 'active',
        ]);
    }

    /**
     * @return array{type: string, external_id: ?string, order: ?NormalizedOrder, inventory_item_id?: ?string, available?: ?int}|null
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        if (! $this->hasValidBodyHmac($request)) {
            return null;
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $payload = (array) $request->json()->all();

        if ($topic === 'app/uninstalled') {
            return ['type' => 'app/uninstalled', 'external_id' => null, 'order' => null];
        }

        if ($topic === 'refunds/create') {
            return [
                'type' => 'refunds/create',
                'external_id' => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
                'order' => null,
            ];
        }

        // Bare `{inventory_item_id, location_id, available}` — no product/SKU
        // in the payload itself (Plan §7.1's `low_stock` trigger), unlike
        // every other topic here which carries a full order object.
        // `syncInventoryLevel()` resolves the rest via the Admin API.
        if ($topic === 'inventory_levels/update' && isset($payload['inventory_item_id'])) {
            return [
                'type' => 'inventory_levels/update',
                'external_id' => null,
                'order' => null,
                'inventory_item_id' => (string) $payload['inventory_item_id'],
                'available' => isset($payload['available']) ? (int) $payload['available'] : null,
            ];
        }

        if (in_array($topic, ['orders/create', 'orders/updated', 'orders/cancelled', 'orders/fulfilled'], true) && isset($payload['id'])) {
            return [
                'type' => $topic,
                'external_id' => (string) $payload['id'],
                'order' => $this->orderMapper->map($payload),
            ];
        }

        return null;
    }

    /**
     * Resolves the variant + parent product behind an
     * `inventory_levels/update` webhook's bare `inventory_item_id` (Plan
     * §7.1) into the same shape `PollWooProductsJob` writes to `products` —
     * so `CheckLowStockAction`/the `low_stock` trigger needs no
     * Shopify-specific branch at all, Woo's poll and Shopify's webhook feed
     * the identical pipeline. Two REST calls (variant lookup by inventory
     * item, then its parent product's title) — same "a few sequential
     * calls per event" shape as `fulfill()`/`refund()` above; assumes a
     * single default location like `fulfill()` does.
     */
    public function syncInventoryLevel(StoreConnection $connection, string $inventoryItemId, ?int $available): ?Product
    {
        $variantResponse = $this->http($connection)->get('/variants.json', [
            'inventory_item_ids' => $inventoryItemId,
        ]);

        if ($variantResponse->failed()) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $variants */
        $variants = (array) $variantResponse->json('variants', []);
        $variant = $variants[0] ?? null;

        if ($variant === null || ! isset($variant['id'])) {
            return null;
        }

        $title = (string) ($variant['title'] ?? '');
        $productId = $variant['product_id'] ?? null;
        $imageUrl = null;

        if ($productId !== null) {
            $productResponse = $this->http($connection)->get("/products/{$productId}.json", ['fields' => 'title,image,images']);

            if ($productResponse->successful()) {
                $title = (string) ($productResponse->json('product.title') ?? $title);
                $imageUrl = $productResponse->json('product.image.src');

                // Same variant-specific-image-over-product-main-image
                // preference as PollShopifyProductsJob — kept consistent
                // between the webhook and poll paths on purpose.
                $variantImageId = $variant['image_id'] ?? null;

                if ($variantImageId !== null) {
                    /** @var array<int, array<string, mixed>> $images */
                    $images = (array) $productResponse->json('product.images', []);
                    $variantImage = collect($images)->firstWhere('id', $variantImageId);
                    $imageUrl = $variantImage['src'] ?? $imageUrl;
                }
            }
        }

        $product = Product::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'external_id' => (string) $variant['id']],
            [
                'team_id' => $connection->team_id,
                'sku' => $variant['sku'] ?? null,
                'title' => $title !== '' ? $title : 'Unknown product',
                'image_url' => $imageUrl,
                'stock_quantity' => $available,
            ],
        );

        $connection->update(['products_synced_at' => now()]);

        return $product;
    }

    /**
     * Uses the Fulfillment Orders API (the modern replacement for the
     * classic `/orders/{id}/fulfillments.json` shortcut, mandatory for
     * apps created after Shopify's 2021 cutover — ours is a 2026 app).
     * Assumes a single default location/fulfillment order — genuine
     * multi-location split fulfillment (per-shipment tracking numbers,
     * one API call per location) is a deliberate scope cut for v1. What
     * this method does guard against: silently fulfilling only the
     * *first* shipment while marking the whole order `shipped` locally,
     * misrepresenting a partially-fulfilled order as complete — see the
     * multi-open-fulfillment-order check below.
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $connection = $order->connection;

        $foResponse = $this->http($connection)->get("/orders/{$order->external_id}/fulfillment_orders.json");

        if ($foResponse->failed()) {
            return ActionResult::failure('Could not look up Shopify fulfillment orders.');
        }

        /** @var array<int, array<string, mixed>> $fulfillmentOrders */
        $fulfillmentOrders = (array) $foResponse->json('fulfillment_orders', []);
        $openOrders = collect($fulfillmentOrders)->where('status', 'open')->values();

        if ($openOrders->count() > 1) {
            return ActionResult::failure('This order ships from multiple locations — fulfill each shipment separately in Shopify Admin rather than here, so tracking gets attached to the right one.');
        }

        $open = $openOrders->first() ?? ($fulfillmentOrders[0] ?? null);

        if ($open === null) {
            return ActionResult::failure('No open Shopify fulfillment order found for this order.');
        }

        $response = $this->http($connection)->post('/fulfillments.json', [
            'fulfillment' => [
                'line_items_by_fulfillment_order' => [
                    ['fulfillment_order_id' => $open['id']],
                ],
                'tracking_info' => [
                    'number' => $data->trackingNumber,
                    'company' => $data->carrier,
                ],
                'notify_customer' => true,
            ],
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Shopify rejected the fulfillment.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Order marked fulfilled.');
    }

    /**
     * A transaction-level refund (against the original sale/capture
     * transaction) rather than a line-item-level one — matches our
     * amount-based `RefundData` (no per-item breakdown), same shape as
     * WooCommerceAdapter's refund.
     */
    public function refund(Order $order, RefundData $data): ActionResult
    {
        $connection = $order->connection;

        $txResponse = $this->http($connection)->get("/orders/{$order->external_id}/transactions.json");

        if ($txResponse->failed()) {
            return ActionResult::failure('Could not look up the original Shopify payment transaction.');
        }

        /** @var array<int, array<string, mixed>> $transactions */
        $transactions = (array) $txResponse->json('transactions', []);
        $original = collect($transactions)->first(
            fn (array $t) => in_array($t['kind'] ?? null, ['sale', 'capture'], true) && ($t['status'] ?? null) === 'success'
        );

        if ($original === null) {
            return ActionResult::failure('No successful payment transaction found to refund against.');
        }

        $amount = $data->amount ?? (float) $order->total;

        $response = $this->http($connection)->post("/orders/{$order->external_id}/refunds.json", [
            'refund' => [
                'notify' => true,
                'note' => $data->reason,
                'transactions' => [
                    [
                        'parent_id' => $original['id'],
                        'amount' => (string) $amount,
                        'kind' => 'refund',
                        'gateway' => $original['gateway'],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Shopify rejected the refund.');
        }

        $isFullRefund = $data->amount === null || $data->amount >= $order->total;

        $order->update([
            'status' => Order::STATUS_REFUNDED,
            'payment_status' => $isFullRefund ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PARTIALLY_REFUNDED,
            'check_at' => null,
        ]);

        RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_REFUND_SPIKE);

        return ActionResult::success('Refund issued.');
    }

    /**
     * Shopify only accepts `customer|inventory|fraud|declined|other` as
     * the cancel reason — our own `$reason` is free text from the
     * merchant, so it's always sent as `other` rather than guessing a
     * mapping that could misrepresent why the merchant cancelled.
     */
    public function cancel(Order $order, ?string $reason): ActionResult
    {
        $response = $this->http($order->connection)->post("/orders/{$order->external_id}/cancel.json", [
            'reason' => 'other',
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Shopify rejected the cancellation.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);

        return ActionResult::success('Order cancelled.');
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            realtimeOrders: true,
            fulfillTracking: true,
            refunds: true,
            cancel: true,
            messagingMode: 'email',
            inventoryUpdate: true,
            // No real review/feedback fetch code exists for Shopify (Plan
            // §4.15) — Shopify has no first-party product-review system at
            // all (reviews normally come from a third-party app like
            // Judge.me, not the core Admin API), and this was previously
            // declared `true` with nothing behind it. Corrected 2026-07-26.
            reviewsFeedback: false,
            // Real — Shopify Payments exposes a genuine Payouts API
            // (Plan §4.14); see fetchPayouts() below.
            payoutsAvailable: true,
            tagSync: true,
        );
    }

    /**
     * Pushes a StockBeat tag edit to the order's real `tags` field in
     * Shopify — merges rather than overwrites, since a naive "set tags to
     * exactly what StockBeat has" would silently erase a tag added
     * directly in Shopify Admin or by another app on the very next edit.
     * `$previousTags` is the order's StockBeat tag list *before* this
     * edit — diffing it against Shopify's current tags isolates which of
     * Shopify's tags weren't StockBeat's to begin with ("external"); those
     * survive untouched, `$newTags` is applied on top of them. This is
     * also what makes *removing* a StockBeat-added tag actually work:
     * a tag that was in `$previousTags` but not `$newTags` is dropped,
     * not re-added from Shopify's own copy, because it was never
     * classified as external in the first place.
     *
     * Best-effort and silent on failure by design — `UpdateOrderTagsAction`
     * guarantees the local tag write always succeeds (Plan §4.3/§4.17), and
     * Shopify staying out of sync for one edit isn't worth breaking that
     * guarantee over. Logged so a persistent failure is still diagnosable.
     *
     * @param  array<int, string>  $previousTags
     * @param  array<int, string>  $newTags
     */
    public function updateOrderTags(Order $order, array $previousTags, array $newTags): void
    {
        $connection = $order->connection;

        $response = $this->http($connection)->get("/orders/{$order->external_id}.json", ['fields' => 'tags']);

        if ($response->failed()) {
            Log::warning('Shopify order tag sync: could not read current tags', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);

            return;
        }

        $shopifyTags = collect(explode(',', (string) $response->json('order.tags', '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter(fn (string $tag) => $tag !== '');

        $externalTags = $shopifyTags->diff($previousTags);
        $finalTags = $externalTags->merge($newTags)->unique()->values();

        $updateResponse = $this->http($connection)->put("/orders/{$order->external_id}.json", [
            'order' => [
                'id' => $order->external_id,
                'tags' => $finalTags->implode(', '),
            ],
        ]);

        if ($updateResponse->failed()) {
            Log::warning('Shopify order tag sync failed', [
                'order_id' => $order->id,
                'status' => $updateResponse->status(),
            ]);
        }
    }

    /**
     * Polls Shopify Payments' real Payouts API (Plan §4.14) — no webhook
     * exists for this, so it's poll-only via `PollShopifyPayoutsJob`. Only
     * returns data for stores actually using Shopify Payments as their
     * gateway; other gateways simply return an empty list, not an error.
     * `status` is passed through exactly as Shopify reports it
     * (`scheduled`/`in_transit`/`paid`/`failed`/`canceled`) rather than
     * mapped to an invented vocabulary.
     *
     * @return array<int, array{external_id: string, amount: string, currency: string, status: string, arrival_date: ?string, raw: array<string, mixed>}>
     */
    public function fetchPayouts(StoreConnection $connection): array
    {
        $response = $this->http($connection)->get('/shopify_payments/payouts.json', ['limit' => 100]);

        if ($response->failed()) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $payouts */
        $payouts = (array) $response->json('payouts', []);

        return array_map(fn (array $raw) => [
            'external_id' => (string) $raw['id'],
            'amount' => (string) ($raw['amount'] ?? '0.00'),
            'currency' => (string) ($raw['currency'] ?? 'USD'),
            'status' => (string) ($raw['status'] ?? 'paid'),
            'arrival_date' => $raw['date'] ?? null,
            'raw' => $raw,
        ], $payouts);
    }

    /**
     * `products.external_id` is the variant id (set by `syncInventoryLevel()`
     * above), not the inventory item id Shopify's inventory API actually
     * keys on — so this resolves variant → inventory_item_id, then a
     * location (assumes a single default location, same as `fulfill()`),
     * before the real `inventory_levels/set.json` call (Plan §4.13).
     */
    public function updateInventory(Product $product, int $quantity): ActionResult
    {
        $connection = $product->connection;

        $variantResponse = $this->http($connection)->get("/variants/{$product->external_id}.json");

        if ($variantResponse->failed()) {
            return ActionResult::failure('Could not look up the Shopify variant.');
        }

        $inventoryItemId = $variantResponse->json('variant.inventory_item_id');

        if ($inventoryItemId === null) {
            return ActionResult::failure('This variant has no inventory item to update.');
        }

        $locationResponse = $this->http($connection)->get('/locations.json');

        if ($locationResponse->failed()) {
            return ActionResult::failure('Could not look up a Shopify location.');
        }

        $locationId = $locationResponse->json('locations.0.id');

        if ($locationId === null) {
            return ActionResult::failure('This store has no fulfillment location to update stock at.');
        }

        $response = $this->http($connection)->post('/inventory_levels/set.json', [
            'location_id' => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available' => $quantity,
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Shopify rejected the inventory update.');
        }

        $product->update(['stock_quantity' => $quantity]);

        return ActionResult::success('Stock quantity updated.');
    }

    /**
     * Shopify has no native chat/messaging API (Plan §7.1/§7.7,
     * `capabilities()->messagingMode === 'email'`) — `SendInboxMessageAction`
     * never reaches this method for a Shopify thread, it always uses its own
     * email path instead. Reachable only if that routing check were
     * bypassed.
     */
    public function sendMessage(InboxThread $thread, string $body): ActionResult
    {
        throw new LogicException('ShopifyAdapter is email-only for messaging (Plan §7.7) — use SendInboxMessageAction\'s email path instead of ChannelAdapter::sendMessage().');
    }

    /**
     * `capabilities()->reviewReply` is false — `ReplyToReviewAction` checks
     * that before ever calling an adapter, so this is reachable only if
     * that check were bypassed, which would itself be the bug worth
     * surfacing loudly here (Plan §4.15).
     */
    public function replyToReview(Review $review, string $body): ActionResult
    {
        throw new LogicException('ShopifyAdapter does not support review replies — Shopify has no first-party review system at all.');
    }

    private function http(StoreConnection $connection): PendingRequest
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $shop = (string) ($credentials['shop_domain'] ?? '');
        $token = (string) ($credentials['access_token'] ?? '');

        return Http::baseUrl("https://{$shop}/admin/api/".self::API_VERSION)
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->acceptJson();
    }

    private function hasValidQueryHmac(Request $request): bool
    {
        $params = $request->query();
        $hmac = $params['hmac'] ?? null;

        if (! is_string($hmac)) {
            return false;
        }

        unset($params['hmac'], $params['signature']);
        ksort($params);

        // Shopify signs the raw decoded key=value pairs with no re-encoding —
        // http_build_query() re-encodes values using PHP's own URL-encoding
        // rules (e.g. spaces as `+`, different treatment of `/`, `=`, `+`
        // inside base64 params like `state`/`host`), producing a different
        // byte string than what Shopify hashed and failing verification on
        // every real callback.
        $message = implode('&', array_map(
            fn (string $key, mixed $value): string => "{$key}={$value}",
            array_keys($params),
            $params,
        ));

        $computed = hash_hmac('sha256', $message, (string) config('services.shopify.client_secret'));

        return hash_equals($computed, $hmac);
    }

    private function hasValidBodyHmac(Request $request): bool
    {
        $signature = $request->header('X-Shopify-Hmac-Sha256');

        if ($signature === null) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), (string) config('services.shopify.client_secret'), true));

        return hash_equals($expected, $signature);
    }

    /**
     * Unlike `AmazonAdapter`/`TikTokAdapter`, this wasn't guarded until
     * this pass — `authorizationUrl()` would silently build a URL with an
     * empty `client_id` instead of failing cleanly when the Partner app
     * credentials aren't configured (Plan §15.2). Same pattern as those
     * two now.
     */
    private function assertConfigured(): void
    {
        if (
            ! is_string(config('services.shopify.client_id')) || config('services.shopify.client_id') === ''
            || ! is_string(config('services.shopify.client_secret')) || config('services.shopify.client_secret') === ''
        ) {
            throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_SHOPIFY);
        }
    }
}
