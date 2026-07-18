<?php

namespace App\Support\Connections\Adapters;

use App\Contracts\ChannelAdapter;
use App\Contracts\OAuthChannelAdapter;
use App\Jobs\RuleEvaluationJob;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\Product;
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
    ) {}

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $shopDomain = (string) ($startCredentials['shop_domain'] ?? '');

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
        ]);

        $accessToken = $tokenResponse->json('access_token');

        if ($tokenResponse->failed() || ! is_string($accessToken)) {
            throw ValidationException::withMessages(['shopify' => 'Could not complete the Shopify connection.']);
        }

        $connection = StoreConnection::query()->create([
            'team_id' => $team->id,
            'platform' => StoreConnection::PLATFORM_SHOPIFY,
            'name' => $name,
            'credentials' => [
                'shop_domain' => $shop,
                'access_token' => $accessToken,
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);

        $this->registerWebhooks($connection);

        return $connection;
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

    public function refreshAuth(StoreConnection $connection): void
    {
        // Offline-access tokens (the default for a non-embedded app) don't
        // expire — nothing to refresh, same as WooCommerce's key pair.
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

        if ($productId !== null) {
            $productResponse = $this->http($connection)->get("/products/{$productId}.json", ['fields' => 'title']);

            if ($productResponse->successful()) {
                $title = (string) ($productResponse->json('product.title') ?? $title);
            }
        }

        return Product::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'external_id' => (string) $variant['id']],
            [
                'team_id' => $connection->team_id,
                'sku' => $variant['sku'] ?? null,
                'title' => $title !== '' ? $title : 'Unknown product',
                'stock_quantity' => $available,
            ],
        );
    }

    /**
     * Uses the Fulfillment Orders API (the modern replacement for the
     * classic `/orders/{id}/fulfillments.json` shortcut, mandatory for
     * apps created after Shopify's 2021 cutover — ours is a 2026 app).
     * Assumes a single default location/fulfillment order — multi-location
     * split fulfillment is a deliberate scope cut for v1.
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
        $open = collect($fulfillmentOrders)->firstWhere('status', 'open') ?? ($fulfillmentOrders[0] ?? null);

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
            reviewsFeedback: true,
        );
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

        $computed = hash_hmac('sha256', http_build_query($params), (string) config('services.shopify.client_secret'));

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
}
