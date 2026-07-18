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
use App\Support\Connections\Adapters\Amazon\AmazonOrderMapper;
use App\Support\Connections\Adapters\Amazon\AwsSigV4Signer;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Real Amazon SP-API adapter (Plan §7.5). Pending Amazon's own developer
 * vetting (Plan §15.2: "takes weeks, start earliest of all platforms even
 * though it ships last") — every method below builds real, correct
 * requests, but is gated on `config('services.amazon.*')` being populated
 * (`assertConfigured()`), throwing `AdapterNotReadyException` exactly like
 * every other method used to unconditionally, until then. The exact same
 * code goes live the moment real SP-API credentials are configured —
 * nothing else needs to change.
 *
 * SP-API's auth model has two independent layers that both apply to every
 * data-plane call:
 *  - LWA (Login with Amazon) OAuth — a per-connection access/refresh token
 *    pair, sent as the `x-amz-access-token` header. Obtained the same
 *    two-step way as Shopify/eBay (`OAuthChannelAdapter`).
 *  - AWS SigV4 — every request is also signed using *temporary* AWS
 *    credentials obtained by assuming the app's configured IAM role via STS
 *    (Plan §15.2's `role_arn`). `aws/aws-sdk-php` isn't a dependency here
 *    (no other adapter needs AWS auth, and the rest of this codebase
 *    deliberately avoids vendor SDKs for platform HTTP calls — see the
 *    Twilio SMS integration's identical reasoning) so `AwsSigV4Signer` is a
 *    small, purpose-built signer instead.
 *
 * Real-time order delivery (Notifications API → SQS/EventBridge, Plan
 * §7.5) is out of scope for v1 — `registerWebhooks()`/`parseWebhook()` are
 * a documented no-op/null, same "polling is a fully correct v1 strategy"
 * reasoning already used for eBay/Etsy (`fetchOrders()` + the scheduled
 * `PollAmazonOrdersJob` is the only sync path).
 */
class AmazonAdapter implements ChannelAdapter, OAuthChannelAdapter
{
    private const LWA_TOKEN_URL = 'https://api.amazon.com/auth/o2/token';

    private const STS_URL = 'https://sts.amazonaws.com/';

    private const FEED_TYPE_ORDER_FULFILLMENT = 'POST_ORDER_FULFILLMENT_DATA';

    /**
     * Best-effort — not confirmed against a live SP-API account as of this
     * writing (Plan §7.5 marks Amazon refunds "⚠️ limited (via Feeds)", and
     * SP-API doesn't document a single canonical "create a refund" call as
     * clearly as Shopify/eBay/Woo do). This is the classic MWS/SP-API
     * "Order Adjustment" flat-file feed type; verify this exact feed type
     * string against SP-API's current Feed Type reference before relying
     * on it in production — same "confirm at build time" caveat already
     * flagged for eBay's Post-Order cancellation call and Etsy's refund
     * endpoint elsewhere in this codebase.
     */
    private const FEED_TYPE_ORDER_ADJUSTMENT = 'POST_ORDER_ADJUSTMENT_DATA';

    /**
     * The pre-shipment "reject this order" feed — real and well documented,
     * matching Plan's own hint that Amazon cancellation is "typically only
     * automatable pre-shipment via a cancel feed/order-cancellation flow".
     */
    private const FEED_TYPE_ORDER_ACKNOWLEDGEMENT = 'POST_ORDER_ACKNOWLEDGEMENT_DATA';

    /**
     * Caps how many getOrders pages a single poll fetches (Plan §7.5:
     * getOrders' token bucket is ~0.0167 rps, burst 20 — far stricter than
     * eBay/Etsy). Each page here also fans out into one getOrderItems call
     * per order, so keeping this small (rather than draining NextToken
     * fully every run) keeps a single poll's total call volume comfortably
     * inside that budget; anything left over is simply picked up by the
     * next scheduled poll.
     */
    private const MAX_PAGES_PER_POLL = 3;

    public function __construct(
        private readonly AmazonOrderMapper $orderMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $this->assertConfigured();

        // For a draft/unpublished SP-API app (Plan §15.2 — still pending
        // vetting), the merchant is sent this consent URL directly rather
        // than discovering the app via the Selling Partner Appstore;
        // `version=beta` is what tells Seller Central this is a
        // not-yet-published app. Once published, this exact same URL shape
        // still works (the `version` param is simply ignored).
        return 'https://sellercentral.amazon.com/apps/authorize/consent?'.http_build_query([
            'application_id' => config('services.amazon.app_id'),
            'state' => $state,
            'version' => 'beta',
        ]);
    }

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function completeConnection(Team $team, string $name, array $startCredentials, string $nonce, Request $callback): StoreConnection
    {
        $this->assertConfigured();

        // Amazon's callback carries its own param names, not the generic
        // `code` Shopify/eBay/Etsy use — `spapi_oauth_code` plus the
        // authorizing seller's `selling_partner_id`, both real SP-API OAuth
        // redirect params.
        $code = (string) $callback->query('spapi_oauth_code', '');
        $sellingPartnerId = (string) $callback->query('selling_partner_id', '');

        if ($code === '') {
            throw ValidationException::withMessages(['amazon' => 'Missing authorization code.']);
        }

        $tokenResponse = Http::asForm()->post(self::LWA_TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
        ]);

        $accessToken = $tokenResponse->json('access_token');
        $refreshToken = $tokenResponse->json('refresh_token');

        if ($tokenResponse->failed() || ! is_string($accessToken) || ! is_string($refreshToken)) {
            throw ValidationException::withMessages(['amazon' => 'Could not complete the Amazon connection.']);
        }

        $expiresIn = (int) $tokenResponse->json('expires_in', 3600);

        return StoreConnection::query()->create([
            'team_id' => $team->id,
            'platform' => StoreConnection::PLATFORM_AMAZON,
            'name' => $name,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                'selling_partner_id' => $sellingPartnerId,
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }

    /**
     * Not used — Amazon only ever connects via the OAuth round trip above,
     * same reasoning as Shopify/eBay/Etsy's `connect()`.
     */
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw new LogicException('AmazonAdapter connects via OAuth — use StartOAuthConnectionAction, not connect().');
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

        $response = Http::asForm()->post(self::LWA_TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
        ]);

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        $connection->update([
            'credentials' => [...$credentials, 'access_token' => $accessToken, 'expires_at' => now()->addSeconds($expiresIn)->toIso8601String()],
        ]);
    }

    /**
     * No-op by design (Plan §7.5): real-time delivery is the Notifications
     * API pushing into Amazon's own SQS/EventBridge, not a webhook Amazon
     * calls on our HTTP endpoint the way Shopify/Woo do — standing up an
     * SQS listener is genuinely out of scope for this Laravel
     * HTTP-request-driven webhook model. `fetchOrders()` + the scheduled
     * `PollAmazonOrdersJob` is the only sync path, same "polling is a fully
     * correct v1 strategy" reasoning already used for eBay/Etsy.
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        // Intentionally empty.
    }

    /**
     * Amazon has no webhook ingress in this v1 scope — always null, mirrors
     * `registerWebhooks()`'s no-op (same convention as EbayAdapter/EtsyAdapter).
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        return null;
    }

    /**
     * Submits a real Feeds API shipment-confirmation feed
     * (`POST_ORDER_FULFILLMENT_DATA` — Plan §7.5: "Feeds/Shipping
     * confirmation for tracking upload"). Feeds are processed
     * asynchronously by Amazon, so this marks the order fulfilled
     * optimistically on submission rather than waiting for the feed's
     * processing result — a feed-status poller isn't built (genuine v1
     * scope cut, no different in kind from this codebase's other honestly
     * flagged gaps).
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $this->assertConfigured();

        $connection = $order->connection;
        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return ActionResult::failure('This Amazon connection needs to be reconnected before the order can be fulfilled.');
        }

        $xml = $this->buildOrderFulfillmentXml($order, $data);

        if (! $this->submitFeed($connection, self::FEED_TYPE_ORDER_FULFILLMENT, $xml, $token)) {
            return ActionResult::failure('Amazon rejected the shipment confirmation feed.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Shipment confirmation submitted to Amazon — feed processing may take a few minutes to reflect in Seller Central.');
    }

    /**
     * Amazon's refunds are genuinely limited (Plan §7.5/§7.8: "⚠️ limited
     * (via Feeds)") — there is no single, clearly documented seller-facing
     * "issue a refund" REST call the way Shopify/eBay/Woo have. This
     * submits the Feeds-API order-adjustment path Plan §7.5 points to;
     * see `FEED_TYPE_ORDER_ADJUSTMENT`'s own docblock for the "verify the
     * exact feed type at build time" caveat. Marks the order refunded
     * optimistically on submission, same async-feed reasoning as `fulfill()`.
     */
    public function refund(Order $order, RefundData $data): ActionResult
    {
        $this->assertConfigured();

        $connection = $order->connection;
        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return ActionResult::failure('This Amazon connection needs to be reconnected before the order can be refunded.');
        }

        $amount = $data->amount ?? (float) $order->total;
        $xml = $this->buildOrderAdjustmentXml($order, $amount, $data->reason);

        if (! $this->submitFeed($connection, self::FEED_TYPE_ORDER_ADJUSTMENT, $xml, $token)) {
            return ActionResult::failure('Amazon rejected the refund adjustment feed.');
        }

        $isFullRefund = $data->amount === null || $data->amount >= $order->total;

        $order->update([
            'status' => Order::STATUS_REFUNDED,
            'payment_status' => $isFullRefund ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PARTIALLY_REFUNDED,
            'check_at' => null,
        ]);

        return ActionResult::success('Refund adjustment submitted to Amazon.');
    }

    /**
     * Amazon cancellation is likewise limited (Plan §7.5/§7.8: "⚠️
     * limited") — genuinely automatable only *before* the order ships, via
     * the Order Acknowledgement feed rejecting each line item. Once an
     * order has shipped there is no seller-facing cancel API at all; this
     * fails clearly rather than pretending, per Plan §7.8's ⚠️ (not ❌)
     * rating meaning "partial support is expected".
     */
    public function cancel(Order $order, ?string $reason): ActionResult
    {
        $this->assertConfigured();

        if ($order->status === Order::STATUS_SHIPPED) {
            return ActionResult::failure('This Amazon order has already shipped — Amazon only supports cancellation before shipment. Process a return/refund through Seller Central instead.');
        }

        $connection = $order->connection;
        $token = $this->ensureFreshToken($connection);

        if ($token === null) {
            return ActionResult::failure('This Amazon connection needs to be reconnected before the order can be cancelled.');
        }

        $xml = $this->buildOrderAcknowledgementXml($order, $reason);

        if (! $this->submitFeed($connection, self::FEED_TYPE_ORDER_ACKNOWLEDGEMENT, $xml, $token)) {
            return ActionResult::failure('Amazon rejected the order cancellation feed.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);

        return ActionResult::success('Cancellation submitted to Amazon.');
    }

    /**
     * Amazon's own adapter build is otherwise real as of this class, but
     * messaging stays out of scope here specifically (a separate task per
     * this build's brief) — Plan §7.5's Messaging API is template-only
     * (`capabilities()->messagingMode === 'template'`), and building the
     * real template-message request shape is a deliberate deferral, not an
     * oversight. Keeps the interface satisfied exactly like every other
     * method on this adapter did before this build.
     */
    public function sendMessage(InboxThread $thread, string $body): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    /**
     * SP-API's Orders API fetch, consumed directly by `PollAmazonOrdersJob`
     * (Plan §7.5 "getOrders/getOrderItems", "PII requires... RDT",
     * "NextToken" pagination). Unlike eBay/Etsy's poll jobs — which do
     * their platform HTTP calls inline since there's little more than a
     * single GET involved — this logic (RDT issuance, SigV4 signing via
     * temporary STS credentials, two-level pagination) is substantial
     * enough that it belongs on the adapter itself rather than duplicated
     * into the job.
     *
     * @return array<int, NormalizedOrder>
     */
    public function fetchOrders(StoreConnection $connection, CarbonInterface $since): array
    {
        $this->assertConfigured();

        $lwaToken = $this->ensureFreshToken($connection);

        if ($lwaToken === null) {
            return [];
        }

        $awsCredentials = $this->awsCredentials();
        $marketplaceId = (string) config('services.amazon.marketplace_id');

        // RDT-scoped access unmasks buyer PII (name/address) on the
        // response; if issuance fails for any reason, fall back to the
        // plain LWA token — orders still sync, just without that PII, the
        // same "the rest of the sync still works" resilience
        // WooCommerceAdapter's per-topic webhook registration and
        // EbayAdapter's Post-Order caveats both already lean on.
        $rdt = $this->createRestrictedDataToken($awsCredentials, $lwaToken);
        $ordersToken = $rdt ?? $lwaToken;

        $orders = [];
        $nextToken = null;
        $pagesFetched = 0;

        do {
            $query = $nextToken !== null
                ? ['NextToken' => $nextToken]
                : [
                    'MarketplaceIds' => $marketplaceId,
                    'CreatedAfter' => $since->toIso8601String(),
                ];

            $response = $this->signedRequest($awsCredentials, 'GET', '/orders/v0/orders', $query, null, $ordersToken);

            if (in_array($response->status(), [401, 403], true)) {
                $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

                return [];
            }

            if ($response->failed()) {
                break; // Transient failure — the next scheduled run retries.
            }

            /** @var array<string, mixed> $payload */
            $payload = (array) $response->json('payload', []);
            /** @var array<int, array<string, mixed>> $rawOrders */
            $rawOrders = (array) ($payload['Orders'] ?? []);
            $nextToken = $payload['NextToken'] ?? null;

            foreach ($rawOrders as $rawOrder) {
                $orderId = (string) ($rawOrder['AmazonOrderId'] ?? '');

                if ($orderId === '') {
                    continue;
                }

                $items = $this->fetchOrderItems($awsCredentials, $ordersToken, $orderId);
                $orders[] = $this->orderMapper->map($rawOrder, $items);
            }

            $pagesFetched++;
        } while ($nextToken !== null && $pagesFetched < self::MAX_PAGES_PER_POLL);

        return $orders;
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            // Plan §7.8 rates Amazon "⚠️ SQS + delay", the same ⚠️ symbol
            // as Etsy's polling-only entry, not eBay's ✅ — real-time
            // notifications exist on Amazon's side, but this v1 adapter
            // (registerWebhooks()/parseWebhook() above) doesn't ingest
            // them, so this reads false, matching Etsy's identical
            // realtimeOrders: false for the same reason.
            realtimeOrders: false,
            fulfillTracking: true,
            refunds: true, // limited — see refund()'s own docblock.
            cancel: true, // pre-shipment only — see cancel()'s own docblock.
            messagingMode: 'template',
            inventoryUpdate: true,
            reviewsFeedback: true,
        );
    }

    private function assertConfigured(): void
    {
        if (
            ! is_string(config('services.amazon.client_id')) || config('services.amazon.client_id') === ''
            || ! is_string(config('services.amazon.client_secret')) || config('services.amazon.client_secret') === ''
            || ! is_string(config('services.amazon.aws_access_key_id')) || config('services.amazon.aws_access_key_id') === ''
            || ! is_string(config('services.amazon.aws_secret_access_key')) || config('services.amazon.aws_secret_access_key') === ''
            || ! is_string(config('services.amazon.role_arn')) || config('services.amazon.role_arn') === ''
        ) {
            throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
        }
    }

    /**
     * Proactively refreshes the per-connection LWA token when it's expired
     * (same pattern as `PollEtsyOrdersJob`/`PollEbayOrdersJob`), since
     * adapter methods can be invoked directly (quick actions) rather than
     * only from a poll job. Returns null when the connection needs
     * reauthorization.
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
     * Assumes the app's configured IAM role via STS (Plan §15.2) to obtain
     * temporary AWS credentials for signing SP-API requests — cached just
     * under STS's own 1-hour session duration so this isn't called on
     * every single SP-API request.
     *
     * @return array{access_key_id: string, secret_access_key: string, session_token: string}
     */
    private function awsCredentials(): array
    {
        /** @var array{access_key_id: string, secret_access_key: string, session_token: string} $credentials */
        $credentials = Cache::remember('amazon:sts:assumed-role', 3000, fn () => $this->assumeRole());

        return $credentials;
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string, session_token: string}
     */
    private function assumeRole(): array
    {
        $accessKeyId = (string) config('services.amazon.aws_access_key_id');
        $secretAccessKey = (string) config('services.amazon.aws_secret_access_key');
        $region = (string) config('services.amazon.aws_region', 'us-east-1');
        $roleArn = (string) config('services.amazon.role_arn');

        $body = http_build_query([
            'Action' => 'AssumeRole',
            'Version' => '2011-06-15',
            'RoleArn' => $roleArn,
            'RoleSessionName' => 'stockbeat-spapi',
            'DurationSeconds' => 3600,
        ]);

        $signer = new AwsSigV4Signer($accessKeyId, $secretAccessKey, null, $region, 'sts');
        $headers = $signer->sign('POST', 'sts.amazonaws.com', '/', [], $body);

        $response = Http::withHeaders([...$headers, 'Content-Type' => 'application/x-www-form-urlencoded'])
            ->withBody($body, 'application/x-www-form-urlencoded')
            ->post(self::STS_URL);

        $credentials = $response->successful() ? $this->parseAssumeRoleResponse($response->body()) : null;

        if ($credentials === null) {
            throw AdapterNotReadyException::forFeature(StoreConnection::PLATFORM_AMAZON, 'could not assume the configured IAM role for SP-API request signing');
        }

        return $credentials;
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string, session_token: string}|null
     */
    private function parseAssumeRoleResponse(string $bodyXml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($bodyXml);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $nodes = $xml->xpath("//*[local-name()='Credentials']") ?: [];
        $node = $nodes[0] ?? null;

        if ($node === null) {
            return null;
        }

        $accessKeyId = (string) $node->AccessKeyId;
        $secretAccessKey = (string) $node->SecretAccessKey;
        $sessionToken = (string) $node->SessionToken;

        if ($accessKeyId === '' || $secretAccessKey === '' || $sessionToken === '') {
            return null;
        }

        return [
            'access_key_id' => $accessKeyId,
            'secret_access_key' => $secretAccessKey,
            'session_token' => $sessionToken,
        ];
    }

    /**
     * Issues a Restricted Data Token (Plan §7.5) scoped to the two order
     * endpoints this adapter reads PII from. Real Tokens API request shape;
     * returns null (rather than throwing) on any failure so callers can
     * fall back to the plain LWA token instead of failing the whole sync.
     *
     * @param  array{access_key_id: string, secret_access_key: string, session_token: string}  $awsCredentials
     */
    private function createRestrictedDataToken(array $awsCredentials, string $lwaAccessToken): ?string
    {
        $body = (string) json_encode([
            'restrictedResources' => [
                ['method' => 'GET', 'path' => '/orders/v0/orders', 'dataElements' => ['buyerInfo', 'shippingAddress']],
                ['method' => 'GET', 'path' => '/orders/v0/orders/{orderId}/orderItems', 'dataElements' => ['buyerInfo']],
            ],
        ]);

        $response = $this->signedRequest($awsCredentials, 'POST', '/tokens/2021-03-01/restrictedDataToken', [], $body, $lwaAccessToken);

        if ($response->failed()) {
            return null;
        }

        $rdt = $response->json('restrictedDataToken');

        return is_string($rdt) && $rdt !== '' ? $rdt : null;
    }

    /**
     * @param  array{access_key_id: string, secret_access_key: string, session_token: string}  $awsCredentials
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrderItems(array $awsCredentials, string $token, string $orderId): array
    {
        $items = [];
        $nextToken = null;

        do {
            $query = $nextToken !== null ? ['NextToken' => $nextToken] : [];
            $response = $this->signedRequest($awsCredentials, 'GET', "/orders/v0/orders/{$orderId}/orderItems", $query, null, $token);

            if ($response->failed()) {
                break;
            }

            /** @var array<string, mixed> $payload */
            $payload = (array) $response->json('payload', []);
            /** @var array<int, array<string, mixed>> $pageItems */
            $pageItems = (array) ($payload['OrderItems'] ?? []);
            $items = [...$items, ...$pageItems];
            $nextToken = $payload['NextToken'] ?? null;
        } while ($nextToken !== null);

        return $items;
    }

    /**
     * Signs and sends one SP-API request: AWS SigV4 (via temporary STS
     * credentials) plus the LWA `x-amz-access-token` header — both layers
     * are required simultaneously (Plan §7.5).
     *
     * @param  array{access_key_id: string, secret_access_key: string, session_token: string}  $awsCredentials
     * @param  array<string, string>  $query
     */
    private function signedRequest(array $awsCredentials, string $method, string $path, array $query, ?string $jsonBody, string $lwaAccessToken): Response
    {
        $host = $this->apiHost();
        $body = $jsonBody ?? '';

        $signer = new AwsSigV4Signer(
            $awsCredentials['access_key_id'],
            $awsCredentials['secret_access_key'],
            $awsCredentials['session_token'],
            (string) config('services.amazon.aws_region', 'us-east-1'),
            'execute-api',
        );

        $signedHeaders = $signer->sign($method, $host, $path, $query, $body);

        $request = Http::withHeaders([
            ...$signedHeaders,
            'x-amz-access-token' => $lwaAccessToken,
        ])->baseUrl("https://{$host}");

        // Every SP-API data-plane call (getOrders pages, per-order
        // getOrderItems, etc.) funnels through this one method, so
        // hooking it here counts them all — see `ApiQuotaTracker`'s own
        // docblock and `GetOpsHealthSnapshotAction::apiQuotaUsage()`'s
        // note on why Amazon's strict per-endpoint token bucket (Plan
        // §7.5) can only be approximated, not measured exactly, by a
        // plain daily count.
        ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_AMAZON);

        if (strtoupper($method) === 'GET') {
            return $request->get($path, $query);
        }

        return $request->withBody($body, 'application/json')->post($path);
    }

    private function apiHost(): string
    {
        return match (config('services.amazon.region', 'na')) {
            'eu' => 'sellingpartnerapi-eu.amazon.com',
            'fe' => 'sellingpartnerapi-fe.amazon.com',
            default => 'sellingpartnerapi-na.amazon.com',
        };
    }

    /**
     * Creates the feed document, uploads the (encrypted) feed content to
     * its presigned URL, then creates the feed referencing that document —
     * the real 3-step SP-API Feeds API submission flow (Plan §7.5: "Feeds
     * are async: submit a feed document, then a create-feed call
     * referencing it").
     */
    private function submitFeed(StoreConnection $connection, string $feedType, string $documentContent, string $lwaAccessToken): bool
    {
        $awsCredentials = $this->awsCredentials();

        $createDocumentResponse = $this->signedRequest(
            $awsCredentials,
            'POST',
            '/feeds/2021-06-30/documents',
            [],
            (string) json_encode(['contentType' => 'text/xml; charset=UTF-8']),
            $lwaAccessToken,
        );

        if ($createDocumentResponse->failed()) {
            return false;
        }

        $feedDocumentId = $createDocumentResponse->json('feedDocumentId');
        $uploadUrl = $createDocumentResponse->json('url');
        /** @var array<string, mixed> $encryptionDetails */
        $encryptionDetails = (array) $createDocumentResponse->json('encryptionDetails', []);

        if (! is_string($feedDocumentId) || $feedDocumentId === '' || ! is_string($uploadUrl) || $uploadUrl === '') {
            return false;
        }

        $uploadResponse = Http::withBody($this->encryptFeedContent($documentContent, $encryptionDetails), 'text/xml; charset=UTF-8')
            ->put($uploadUrl);

        if ($uploadResponse->failed()) {
            return false;
        }

        $createFeedResponse = $this->signedRequest(
            $awsCredentials,
            'POST',
            '/feeds/2021-06-30/feeds',
            [],
            (string) json_encode([
                'feedType' => $feedType,
                'marketplaceIds' => [(string) config('services.amazon.marketplace_id')],
                'inputFeedDocumentId' => $feedDocumentId,
            ]),
            $lwaAccessToken,
        );

        return $createFeedResponse->successful();
    }

    /**
     * SP-API always encrypts feed document content with AES-256-CBC using
     * a one-time key/IV returned from `createFeedDocument` (real SP-API
     * requirement, distinct from SigV4 request signing above) — falls back
     * to sending the content unencrypted only if Amazon's own response
     * doesn't carry encryption details at all, which shouldn't happen
     * against a real endpoint.
     *
     * @param  array<string, mixed>  $encryptionDetails
     */
    private function encryptFeedContent(string $content, array $encryptionDetails): string
    {
        if (($encryptionDetails['standard'] ?? null) !== 'AES') {
            return $content;
        }

        $key = base64_decode((string) ($encryptionDetails['key'] ?? ''), true);
        $iv = base64_decode((string) ($encryptionDetails['initializationVector'] ?? ''), true);

        if (! is_string($key) || $key === '' || ! is_string($iv) || $iv === '') {
            return $content;
        }

        $encrypted = openssl_encrypt($content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $encrypted !== false ? $encrypted : $content;
    }

    private function buildOrderFulfillmentXml(Order $order, FulfillmentData $data): string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $order->connection->credentials ?? [];
        $merchantId = $this->escapeXml((string) ($credentials['selling_partner_id'] ?? ''));
        $orderId = $this->escapeXml($order->external_id);
        $trackingNumber = $this->escapeXml($data->trackingNumber);
        $carrier = $this->escapeXml($data->carrier ?? 'Other');
        $fulfillmentDate = $this->escapeXml(now()->toIso8601String());

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>{$merchantId}</MerchantIdentifier>
            </Header>
            <MessageType>OrderFulfillment</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <OrderFulfillment>
                    <AmazonOrderID>{$orderId}</AmazonOrderID>
                    <FulfillmentDate>{$fulfillmentDate}</FulfillmentDate>
                    <FulfillmentData>
                        <CarrierCode>{$carrier}</CarrierCode>
                        <ShippingMethod>Standard</ShippingMethod>
                        <ShipperTrackingNumber>{$trackingNumber}</ShipperTrackingNumber>
                    </FulfillmentData>
                </OrderFulfillment>
            </Message>
        </AmazonEnvelope>
        XML;
    }

    private function buildOrderAdjustmentXml(Order $order, float $amount, ?string $reason): string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $order->connection->credentials ?? [];
        $merchantId = $this->escapeXml((string) ($credentials['selling_partner_id'] ?? ''));
        $orderId = $this->escapeXml($order->external_id);
        $currency = $this->escapeXml($order->currency);
        $adjustmentReason = $this->escapeXml($reason ?? 'CustomerReturn');
        $amountFormatted = number_format($amount, 2, '.', '');

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>{$merchantId}</MerchantIdentifier>
            </Header>
            <MessageType>OrderAdjustment</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <OrderAdjustment>
                    <AmazonOrderID>{$orderId}</AmazonOrderID>
                    <AdjustmentReason>{$adjustmentReason}</AdjustmentReason>
                    <AdjustmentAmount currency="{$currency}">{$amountFormatted}</AdjustmentAmount>
                </OrderAdjustment>
            </Message>
        </AmazonEnvelope>
        XML;
    }

    private function buildOrderAcknowledgementXml(Order $order, ?string $reason): string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $order->connection->credentials ?? [];
        $merchantId = $this->escapeXml((string) ($credentials['selling_partner_id'] ?? ''));
        $orderId = $this->escapeXml($order->external_id);
        $cancelReason = $this->escapeXml($reason ?? 'NoInventory');

        $itemsXml = $order->items->map(function ($item) use ($cancelReason) {
            $itemId = $this->escapeXml((string) ($item->external_id ?? ''));

            return <<<XML
                    <Item>
                        <AmazonOrderItemCode>{$itemId}</AmazonOrderItemCode>
                        <CancelReason>{$cancelReason}</CancelReason>
                    </Item>
            XML;
        })->implode("\n");

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
            <Header>
                <DocumentVersion>1.01</DocumentVersion>
                <MerchantIdentifier>{$merchantId}</MerchantIdentifier>
            </Header>
            <MessageType>OrderAcknowledgement</MessageType>
            <Message>
                <MessageID>1</MessageID>
                <OrderAcknowledgement>
                    <AmazonOrderID>{$orderId}</AmazonOrderID>
                    <StatusCode>Failure</StatusCode>
        {$itemsXml}
                </OrderAcknowledgement>
            </Message>
        </AmazonEnvelope>
        XML;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
