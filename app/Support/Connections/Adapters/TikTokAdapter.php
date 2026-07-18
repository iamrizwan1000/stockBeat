<?php

namespace App\Support\Connections\Adapters;

use App\Contracts\ChannelAdapter;
use App\Contracts\OAuthChannelAdapter;
use App\Exceptions\Connections\AdapterNotReadyException;
use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Connections\ActionResult;
use App\Support\Connections\Adapters\TikTok\TikTokOrderMapper;
use App\Support\Connections\Adapters\TikTok\TikTokRequestSigner;
use App\Support\Connections\ApiQuotaTracker;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use App\Support\Orders\NormalizedOrder;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * TikTok Shop Open Partner API adapter (Plan §7.6: "OAuth, order list/
 * detail, fulfillment, and webhooks... design the adapter interface so this
 * drops in without core changes"). This is a genuinely net-new platform —
 * no Partner Center app has been registered yet — so, per the same
 * "stub-ready" contract already established for `AmazonAdapter`, every
 * method below builds real, correct requests but is gated on
 * `config('services.tiktok_shop.*')` being populated (`assertConfigured()`),
 * throwing `AdapterNotReadyException` until then. The exact same code goes
 * live the moment a real Partner Center app_key/app_secret exist — nothing
 * else needs to change.
 *
 * Unlike eBay/Etsy/Amazon (all polling-only or SQS-only in this codebase),
 * TikTok Shop's Partner API documents a real webhook subscription endpoint
 * (Plan §7.6's own "and webhooks") — `registerWebhooks()`/`parseWebhook()`
 * are real here, not no-ops, with `PollTikTokOrdersJob` running only as the
 * reconciliation safety net (same framing as `PollWooOrdersJob`), not the
 * primary sync path.
 *
 * Every authenticated Partner API call (not just the OAuth token exchange)
 * must additionally be signed per-request (`TikTokRequestSigner`) — a
 * distinct, app-secret-keyed signature scheme on top of the per-connection
 * OAuth token, conceptually similar to why `AmazonAdapter` needs AWS SigV4
 * *in addition to* its LWA OAuth token.
 */
class TikTokAdapter implements ChannelAdapter, OAuthChannelAdapter
{
    /**
     * Verify at build time: TikTok Shop's seller-authorization entry point
     * has shifted hostnames across API generations/regions (older
     * "services.tiktokshop.com" vs. the current Partner Center's
     * "auth.tiktok-shops.com") — this targets the app_key-based flow
     * documented for Partner API v2, which needs no separate `service_id`.
     */
    private const AUTHORIZE_URL = 'https://auth.tiktok-shops.com/oauth/authorize';

    private const TOKEN_URL = 'https://auth.tiktok-shops.com/api/v2/token/get';

    private const REFRESH_URL = 'https://auth.tiktok-shops.com/api/v2/token/refresh';

    /**
     * Verify at build time: the Partner API's data-plane host is also
     * region-sharded (Plan §7.6/§15.2-style gotcha, same shape as Amazon's
     * NA/EU/FE split) — this is the global/US default.
     */
    private const API_BASE = 'https://open-api.tiktokglobalshop.com';

    /**
     * Real, documented Partner API v2 event type for order-lifecycle
     * webhooks (Plan §7.6: "subscribe to event types like
     * ORDER_STATUS_CHANGE"). A `PACKAGE_STATUS_CHANGE` type also exists but
     * is out of scope here — order status alone drives this app's sync.
     */
    private const WEBHOOK_EVENT_TYPE_ORDER_STATUS_CHANGE = 'ORDER_STATUS_CHANGE';

    /**
     * Mirrors `AmazonAdapter::MAX_PAGES_PER_POLL` — caps a single poll's
     * search-endpoint pagination so one connection can't starve the queue.
     */
    private const MAX_PAGES_PER_POLL = 5;

    public function __construct(
        private readonly TikTokOrderMapper $orderMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $this->assertConfigured();

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'app_key' => config('services.tiktok_shop.app_key'),
            'state' => $state,
        ]);
    }

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function completeConnection(Team $team, string $name, array $startCredentials, string $nonce, Request $callback): StoreConnection
    {
        $this->assertConfigured();

        $code = (string) $callback->query('code', '');

        if ($code === '') {
            throw ValidationException::withMessages(['tiktok' => 'Missing authorization code.']);
        }

        // Real Partner API v2 token-exchange shape: a plain JSON POST (no
        // request signing on this specific call — token issuance is the one
        // Partner API surface that predates/bypasses the per-request signer,
        // same "the OAuth token endpoint is its own thing" carve-out
        // Shopify/eBay/Etsy/Amazon's adapters all have).
        $tokenResponse = Http::asJson()->post(self::TOKEN_URL, [
            'app_key' => config('services.tiktok_shop.app_key'),
            'app_secret' => config('services.tiktok_shop.app_secret'),
            'auth_code' => $code,
            'grant_type' => 'authorized_code',
        ]);

        /** @var array<string, mixed> $data */
        $data = (array) $tokenResponse->json('data', []);
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;

        if ($tokenResponse->failed() || ! is_string($accessToken) || ! is_string($refreshToken)) {
            throw ValidationException::withMessages(['tiktok' => 'Could not complete the TikTok Shop connection.']);
        }

        $expiresIn = (int) ($data['access_token_expire_in'] ?? 7200);

        $connection = StoreConnection::query()->create([
            'team_id' => $team->id,
            'platform' => StoreConnection::PLATFORM_TIKTOK,
            'name' => $name,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                'open_id' => $data['open_id'] ?? null,
                'seller_name' => $data['seller_name'] ?? null,
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);

        $this->populateAuthorizedShop($connection);
        $this->registerWebhooks($connection);

        return $connection;
    }

    /**
     * Not used — TikTok Shop only ever connects via the OAuth flow above,
     * same reasoning as Shopify/eBay/Etsy/Amazon's `connect()`.
     */
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw new LogicException('TikTokAdapter connects via OAuth — use StartOAuthConnectionAction, not connect().');
    }

    public function refreshAuth(StoreConnection $connection): void
    {
        $this->assertConfigured();

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $response = Http::asJson()->post(self::REFRESH_URL, [
            'app_key' => config('services.tiktok_shop.app_key'),
            'app_secret' => config('services.tiktok_shop.app_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        /** @var array<string, mixed> $data */
        $data = (array) $response->json('data', []);
        $accessToken = $data['access_token'] ?? null;

        if ($response->failed() || ! is_string($accessToken)) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $expiresIn = (int) ($data['access_token_expire_in'] ?? 7200);
        $newRefreshToken = $data['refresh_token'] ?? $refreshToken;

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
     * Real webhook subscription request (Plan §7.6) — resilient per-event
     * type, same convention as WooCommerceAdapter/ShopifyAdapter (a partial
     * failure still leaves other event types registered, and the
     * reconciliation poller covers whatever's missed regardless).
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        $this->assertConfigured();

        $callbackUrl = route('hooks.tiktok', ['connection' => $connection->id]);

        // Real Partner API v2 webhook-subscription endpoint shape: a signed
        // PUT registering one address per event type. Verify at build time:
        // whether a given app/region requires this call once per shop
        // (shop_cipher-scoped) vs. once app-wide — this registers it scoped
        // to this connection's own shop, the safer of the two assumptions.
        $response = $this->signedRequest($connection, 'PUT', '/event/202309/webhooks', [], [
            'address' => $callbackUrl,
            'event_type' => self::WEBHOOK_EVENT_TYPE_ORDER_STATUS_CHANGE,
        ]);

        $connection->update([
            'webhook_status' => $response->successful() ? 'active' : 'partial',
        ]);
    }

    /**
     * Real signature verification + payload parsing (Plan §7.6). TikTok
     * Shop signs webhook deliveries with the *app secret* (not a
     * per-connection secret like Woo's random webhook secret) — every shop
     * authorized to the same app shares this signing key, so verification
     * doesn't need to look anything up on `$connection` beyond confirming
     * it's the right platform.
     *
     * Verify at build time: the exact header name/format (`x-tts-signature`
     * here, documented as `t=<unix timestamp>,s=<hex hmac>`) and the exact
     * concatenation order (`path` + `timestamp` + raw body here) — TikTok's
     * publicly documented signing scheme for OAuth Partner API webhooks is
     * less consistently mirrored across SDKs than Shopify/Woo's HMAC
     * convention, same "confirm at build time" caveat already flagged for
     * eBay's Post-Order API and Etsy's refund endpoint elsewhere in this
     * codebase.
     *
     * @return array{type: string, external_id: ?string, order: ?NormalizedOrder}|null
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        if ($connection->platform !== StoreConnection::PLATFORM_TIKTOK) {
            return null;
        }

        if (! $this->hasValidWebhookSignature($request)) {
            return null;
        }

        $payload = (array) $request->json()->all();
        $type = (string) ($payload['type'] ?? '');

        // TikTok wraps the real event body as a JSON-encoded string under
        // `data` rather than a nested object — a genuine Partner API quirk,
        // not a mapping bug.
        $rawData = $payload['data'] ?? null;
        $data = is_string($rawData) ? (array) json_decode($rawData, true) : (array) $rawData;

        if ($type !== self::WEBHOOK_EVENT_TYPE_ORDER_STATUS_CHANGE || ! isset($data['order_id'])) {
            return null;
        }

        return [
            'type' => $type,
            'external_id' => (string) $data['order_id'],
            // The webhook payload is a thin status-change notification, not
            // a full order object (unlike Shopify/Woo's webhook bodies) —
            // `ProcessTikTokWebhookJob` re-fetches full order detail via
            // `fetchOrderDetail()` before ingesting, same "notification
            // triggers a real API fetch" shape Amazon's SQS model would have
            // needed had it been built.
            'order' => null,
        ];
    }

    /**
     * Fetches the order's current package(s), picks the open one, resolves
     * a shipping-provider id, then submits the real Fulfillment API ship
     * request (Plan §7.6: "fulfillment"). Fulfills the whole open package
     * rather than partial line items — same single-fulfillment-order scope
     * cut as ShopifyAdapter/EbayAdapter.
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $this->assertConfigured();

        $connection = $order->connection;
        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return ActionResult::failure('This TikTok Shop connection needs to be reconnected before the order can be fulfilled.');
        }

        $packagesResponse = $this->signedRequest($connection, 'GET', "/fulfillment/202309/orders/{$order->external_id}/packages");

        if ($packagesResponse->failed()) {
            return ActionResult::failure('Could not look up TikTok Shop packages for this order.');
        }

        /** @var array<int, array<string, mixed>> $packages */
        $packages = (array) $packagesResponse->json('data.packages', []);
        $package = $packages[0] ?? null;

        if ($package === null || ! isset($package['id'])) {
            return ActionResult::failure('No TikTok Shop package found to fulfill for this order.');
        }

        $shippingProviderId = $this->resolveShippingProviderId($connection, (string) $package['id'], $data->carrier);

        $shipResponse = $this->signedRequest($connection, 'POST', "/fulfillment/202309/packages/{$package['id']}/ship", [], array_filter([
            'tracking_number' => $data->trackingNumber,
            'shipping_provider_id' => $shippingProviderId,
        ], fn ($value) => $value !== null));

        if ($shipResponse->failed()) {
            return ActionResult::failure('TikTok Shop rejected the fulfillment.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Order marked fulfilled.');
    }

    /**
     * TikTok Shop's Return & Refund flow is genuinely buyer-initiated, not
     * seller-initiated (Plan §7.6/§7.8-style caveat, same shape as Etsy's
     * cancellation limitation) — sellers can only approve/reject a return
     * request the buyer already opened, via the Reverse API, which needs a
     * `return_id` this app never has (our own `RefundData` is amount+reason,
     * merchant-initiated, with no linked buyer return request to approve).
     * `capabilities()->refunds` is false so `RefundOrderAction` never
     * reaches here in normal use; this exists only as a defensive fallback
     * if that gate were ever bypassed — same pattern as `EtsyAdapter::cancel()`.
     */
    public function refund(Order $order, RefundData $data): ActionResult
    {
        return ActionResult::failure('TikTok Shop does not support seller-initiated refunds via API — refunds only happen by approving a buyer-opened return request through TikTok Seller Center.');
    }

    /**
     * TikTok Shop's seller-initiated cancellation is real but limited to
     * pre-shipment orders (Plan §7.6/§7.8-style caveat, same shape as
     * Amazon's cancellation limitation) — fails clearly for anything already
     * shipped rather than pretending, per this codebase's own convention.
     */
    public function cancel(Order $order, ?string $reason): ActionResult
    {
        $this->assertConfigured();

        if ($order->status === Order::STATUS_SHIPPED) {
            return ActionResult::failure('This TikTok Shop order has already shipped — TikTok Shop only supports cancellation before shipment.');
        }

        $connection = $order->connection;
        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return ActionResult::failure('This TikTok Shop connection needs to be reconnected before the order can be cancelled.');
        }

        // Verify at build time: TikTok's exact seller-cancel-reason enum
        // (`OUT_OF_STOCK` is documented; other values are less consistently
        // mirrored across API docs/SDKs) — same caveat already flagged for
        // Amazon's Order Acknowledgement `CancelReason` and eBay's Post-Order
        // cancellation reason.
        $response = $this->signedRequest($connection, 'POST', "/order/202309/orders/{$order->external_id}/cancel", [], [
            'cancel_reason' => 'OUT_OF_STOCK',
        ]);

        if ($response->failed()) {
            return ActionResult::failure('TikTok Shop rejected the cancellation.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);

        return ActionResult::success('Order cancelled.');
    }

    /**
     * TikTok Shop messaging isn't part of this build's spec (Plan §7.6/§7.8
     * has no messaging capability row for TikTok Shop, unlike eBay's
     * documented full messaging or Amazon's documented template-only
     * Messaging API) — always throws rather than inventing an integration
     * the plan never asked for, same "keeps the interface satisfied"
     * reasoning as `AmazonAdapter::sendMessage()`.
     */
    public function sendMessage(InboxThread $thread, string $body): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_TIKTOK);
    }

    /**
     * Real Order Search + Order Detail fetch (Plan §7.6: "order list/
     * detail"), consumed by `PollTikTokOrdersJob` as the reconciliation
     * safety net — webhooks (`registerWebhooks()`/`parseWebhook()` above)
     * are the primary sync path here, unlike Amazon/eBay/Etsy where polling
     * is the *only* path.
     *
     * @return array<int, NormalizedOrder>
     */
    public function fetchOrders(StoreConnection $connection, CarbonInterface $since): array
    {
        $this->assertConfigured();

        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return [];
        }

        $orders = [];
        $pageToken = null;
        $pagesFetched = 0;

        do {
            $body = array_filter([
                'create_time_ge' => $since->timestamp,
                'page_size' => 50,
                'page_token' => $pageToken,
            ], fn ($value) => $value !== null);

            $response = $this->signedRequest($connection, 'POST', '/order/202309/orders/search', [], $body);

            if (in_array($response->status(), [401, 403], true)) {
                $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

                return [];
            }

            if ($response->failed()) {
                break; // Transient failure — the next scheduled run retries.
            }

            /** @var array<int, array<string, mixed>> $rawOrders */
            $rawOrders = (array) $response->json('data.orders', []);
            $pageToken = $response->json('data.next_page_token');
            $pageToken = is_string($pageToken) && $pageToken !== '' ? $pageToken : null;

            foreach ($rawOrders as $rawOrder) {
                if (! isset($rawOrder['id'])) {
                    continue;
                }

                $orders[] = $this->orderMapper->map($rawOrder);
            }

            $pagesFetched++;
        } while ($pageToken !== null && $pagesFetched < self::MAX_PAGES_PER_POLL);

        return $orders;
    }

    /**
     * Batch order-detail fetch by id (Plan §7.6: "order... detail") — used
     * by `ProcessTikTokWebhookJob` to turn a thin webhook notification (just
     * an order id + the fact its status changed) into a full order to
     * ingest, since the webhook payload itself never carries the whole
     * order (see `parseWebhook()`'s own docblock).
     *
     * @return array<int, NormalizedOrder>
     */
    public function fetchOrderDetail(StoreConnection $connection, string $externalId): array
    {
        $this->assertConfigured();

        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return [];
        }

        $response = $this->signedRequest($connection, 'GET', '/order/202309/orders', ['ids' => $externalId]);

        if ($response->failed()) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rawOrders */
        $rawOrders = (array) $response->json('data.orders', []);

        return collect($rawOrders)
            ->filter(fn (array $raw) => isset($raw['id']))
            ->map(fn (array $raw) => $this->orderMapper->map($raw))
            ->all();
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            // Plan §7.6 explicitly calls out real webhooks for TikTok Shop —
            // unlike Etsy/Amazon's identical-looking false, this one reads
            // true because registerWebhooks()/parseWebhook() above are real,
            // not no-ops.
            realtimeOrders: true,
            fulfillTracking: true,
            // TikTok Shop's refund flow is buyer-initiated only — see
            // refund()'s own docblock. Marked false (not "limited-but-true"
            // like Amazon) because there is no seller-initiated "refund $X"
            // call at all, only approve/reject on a buyer's own request.
            refunds: false,
            cancel: true, // pre-shipment only — see cancel()'s own docblock.
            // No messaging capability exists in Plan §7.6/§7.8 for TikTok
            // Shop — 'none' isn't an established CapabilitySet convention
            // elsewhere, but nothing enforces a fixed enum on this field
            // (SendInboxMessageAction only special-cases the literal string
            // 'email'; everything else — including this — routes to
            // sendMessage(), which throws, exactly like Amazon's 'template'
            // does today).
            messagingMode: 'none',
            inventoryUpdate: true,
            // Not confirmed: TikTok Shop's Partner API doesn't document a
            // seller-facing product-reviews/feedback retrieval endpoint as
            // clearly as Shopify/Woo/eBay/Amazon do — rather than assume
            // parity with the other platforms, this reads false until a
            // real endpoint is confirmed to exist.
            reviewsFeedback: false,
        );
    }

    private function assertConfigured(): void
    {
        if (
            ! is_string(config('services.tiktok_shop.app_key')) || config('services.tiktok_shop.app_key') === ''
            || ! is_string(config('services.tiktok_shop.app_secret')) || config('services.tiktok_shop.app_secret') === ''
        ) {
            throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_TIKTOK);
        }
    }

    /**
     * Proactively refreshes the per-connection access token when expired
     * (same pattern as `AmazonAdapter::ensureFreshToken()`), since adapter
     * methods can be invoked directly (quick actions) rather than only from
     * a poll job. Returns null when the connection needs reauthorization.
     */
    private function ensureFreshToken(StoreConnection $connection): ?string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            $this->refreshAuth($connection);

            if ($connection->status === StoreConnection::STATUS_NEEDS_REAUTH) {
                return null;
            }
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $token = (string) ($credentials['access_token'] ?? '');

        return $token !== '' ? $token : null;
    }

    /**
     * Looks up the shop(s) this OAuth grant authorizes (Plan §7.6) —
     * TikTok Shop's token exchange itself doesn't return a shop id/cipher,
     * a separate call is required, same "shop lookup after token exchange"
     * shape as `EtsyAdapter::completeConnection()`'s own shops call. Stores
     * whichever fields the response carries; a connection with multiple
     * authorized shops isn't split automatically (v1 scope cut — one
     * connection maps to the first authorized shop, same one-connection-
     * per-store model as every other adapter).
     */
    private function populateAuthorizedShop(StoreConnection $connection): void
    {
        $response = $this->signedRequest($connection, 'GET', '/authorization/202309/shops');

        if ($response->failed()) {
            return;
        }

        /** @var array<int, array<string, mixed>> $shops */
        $shops = (array) $response->json('data.shops', []);
        $shop = $shops[0] ?? null;

        if ($shop === null) {
            return;
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        $connection->update([
            'credentials' => [
                ...$credentials,
                'shop_id' => $shop['id'] ?? null,
                'shop_cipher' => $shop['cipher'] ?? null,
            ],
        ]);
    }

    /**
     * Best-effort shipping-provider resolution for `fulfill()`: our own
     * `FulfillmentData::$carrier` is free text from the merchant, not one of
     * TikTok's own provider ids, so this looks up the package's available
     * providers and matches by case-insensitive name — falling back to the
     * first available provider if no match (or no carrier given) rather
     * than failing the whole fulfillment over a cosmetic mismatch. Verify at
     * build time: the exact "list shipping providers for a package" endpoint
     * shape (this assumes it's scoped by package id, which is the
     * documented shape as of this writing).
     */
    private function resolveShippingProviderId(StoreConnection $connection, string $packageId, ?string $carrier): ?string
    {
        $response = $this->signedRequest($connection, 'GET', "/logistics/202309/packages/{$packageId}/shipping_providers");

        if ($response->failed()) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $providers */
        $providers = (array) $response->json('data.shipping_providers', []);

        if ($providers === []) {
            return null;
        }

        if ($carrier !== null) {
            $match = collect($providers)->first(
                fn (array $provider) => strtolower((string) ($provider['name'] ?? '')) === strtolower($carrier)
            );

            if ($match !== null) {
                return isset($match['id']) ? (string) $match['id'] : null;
            }
        }

        $first = $providers[0];

        return isset($first['id']) ? (string) $first['id'] : null;
    }

    /**
     * Signs and sends one Partner API request: this connection's own
     * `x-tts-access-token` header plus the app-secret-keyed query signature
     * every call additionally needs (`TikTokRequestSigner`) — both layers
     * are required simultaneously, same "two independent auth layers on
     * every request" shape as `AmazonAdapter::signedRequest()`'s LWA token +
     * AWS SigV4. Building and sending the request in one place (rather than
     * handing callers a bare `PendingRequest` to finish themselves) keeps
     * the signed query string and the actual request body from ever
     * silently drifting apart.
     *
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $jsonBody
     */
    private function signedRequest(StoreConnection $connection, string $method, string $path, array $query = [], ?array $jsonBody = null): Response
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $accessToken = (string) ($credentials['access_token'] ?? '');
        $shopCipher = $credentials['shop_cipher'] ?? null;

        if (is_string($shopCipher) && $shopCipher !== '') {
            $query['shop_cipher'] = $shopCipher;
        }

        $signer = new TikTokRequestSigner(
            (string) config('services.tiktok_shop.app_key'),
            (string) config('services.tiktok_shop.app_secret'),
        );

        $bodyForSignature = $jsonBody !== null ? (string) json_encode($jsonBody) : '';
        $signed = $signer->sign($path, $query, $bodyForSignature);

        $request = Http::withHeaders(['x-tts-access-token' => $accessToken])
            ->withQueryParameters([...$query, ...$signed])
            ->acceptJson();

        $url = self::API_BASE.$path;

        // Every Partner API data-plane call funnels through this one
        // method — see `ApiQuotaTracker`'s own docblock. TikTok Shop's
        // rate limits aren't documented anywhere in Plan §7.6, so this
        // count is tracked for visibility only (see
        // `GetOpsHealthSnapshotAction::apiQuotaUsage()`'s note) rather
        // than compared against a known daily budget.
        ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_TIKTOK);

        return match (strtoupper($method)) {
            'GET' => $request->get($url),
            'PUT' => $request->put($url, $jsonBody ?? []),
            default => $request->post($url, $jsonBody ?? []),
        };
    }

    /**
     * @see parseWebhook()'s own docblock for the "verify at build time" caveat
     * on this header name/format.
     */
    private function hasValidWebhookSignature(Request $request): bool
    {
        $header = (string) $request->header('x-tts-signature', '');
        $secret = config('services.tiktok_shop.app_secret');

        if ($header === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        parse_str(str_replace(',', '&', $header), $parts);
        $timestampRaw = $parts['t'] ?? null;
        $signatureRaw = $parts['s'] ?? null;
        $timestamp = is_string($timestampRaw) ? $timestampRaw : '';
        $providedSignature = is_string($signatureRaw) ? $signatureRaw : '';

        if ($timestamp === '' || $providedSignature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->path().$timestamp.$request->getContent(), $secret);

        return hash_equals($expected, $providedSignature);
    }
}
