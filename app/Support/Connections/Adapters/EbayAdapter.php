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
use App\Support\Connections\ApiQuotaTracker;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Real eBay adapter (Plan §7.3) — OAuth 2.0 authorization-code flow via a
 * Developer Program keyset (sandbox for now). Connects via the two-step
 * OAuth round trip (`OAuthChannelAdapter`), same shape as Shopify.
 *
 * Deliberate v1 scope cut: real-time order delivery via eBay's Platform
 * Notifications/Notification API is NOT implemented — `registerWebhooks()`
 * is a no-op and order sync is polling-only (`PollEbayOrdersJob`), same
 * pattern as Etsy's "no webhooks" adapter. This keeps the surface area
 * reasonable for v1; the reconciliation-poller safety net Plan §7.2
 * recommends anyway means polling alone is a fully correct (if less
 * instant) sync strategy.
 */
class EbayAdapter implements ChannelAdapter, OAuthChannelAdapter
{
    private const SCOPES = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment https://api.ebay.com/oauth/api_scope/sell.inventory';

    /**
     * Legacy Trading API call names (Plan §7.3 gotcha) — member messaging
     * has no REST equivalent, unlike everything else this adapter does.
     */
    private const TRADING_CALL_ADD_MEMBER_MESSAGE = 'AddMemberMessageAAQToPartner';

    private const TRADING_CALL_GET_MEMBER_MESSAGES = 'GetMemberMessages';

    /**
     * Feedback polling (Plan §7.3: "poll feedback via Trading API for
     * negative-feedback alerts") — same legacy Trading API as messaging,
     * no REST equivalent.
     */
    private const TRADING_CALL_GET_FEEDBACK = 'GetFeedback';

    /**
     * Pinned to a recent-enough Trading API version as of this writing —
     * verify against eBay's current released version at build time (Plan
     * §7.3's own "verify at build time" caveats apply here too).
     */
    private const TRADING_API_COMPATIBILITY_LEVEL = '1163';

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $this->assertConfigured();

        $authHost = $this->isSandbox() ? 'auth.sandbox.ebay.com' : 'auth.ebay.com';

        return "https://{$authHost}/oauth2/authorize?".http_build_query([
            'client_id' => config('services.ebay.app_id'),
            // eBay's own quirk: this param is the RuName, not a literal URL
            // — the RuName itself encodes the real callback URL configured
            // in the Developer Portal.
            'redirect_uri' => config('services.ebay.ru_name'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
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
            throw ValidationException::withMessages(['ebay' => 'Missing authorization code.']);
        }

        $tokenResponse = $this->tokenClient()->asForm()->post($this->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.ebay.ru_name'),
        ]);

        $accessToken = $tokenResponse->json('access_token');
        $refreshToken = $tokenResponse->json('refresh_token');

        if ($tokenResponse->failed() || ! is_string($accessToken) || ! is_string($refreshToken)) {
            throw ValidationException::withMessages(['ebay' => 'Could not complete the eBay connection.']);
        }

        $expiresIn = (int) $tokenResponse->json('expires_in', 7200);

        return StoreConnection::query()->create([
            'team_id' => $team->id,
            'platform' => StoreConnection::PLATFORM_EBAY,
            'name' => $name,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }

    /**
     * Not used — eBay only ever connects via the OAuth flow above, same
     * reasoning as ShopifyAdapter's `connect()`.
     */
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw new LogicException('EbayAdapter connects via OAuth — use StartOAuthConnectionAction, not connect().');
    }

    /**
     * eBay access tokens expire after ~2 hours; refresh tokens last up to
     * 18 months (Plan §7.3 gotcha: "token refresh must be rock-solid").
     */
    public function refreshAuth(StoreConnection $connection): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $response = $this->tokenClient()->asForm()->post($this->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => self::SCOPES,
        ]);

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $expiresIn = (int) $response->json('expires_in', 7200);

        $connection->update([
            'credentials' => [...$credentials, 'access_token' => $accessToken, 'expires_at' => now()->addSeconds($expiresIn)->toIso8601String()],
        ]);
    }

    /**
     * No-op by design — see class docblock. eBay's real-time delivery
     * (Platform Notifications) is a deliberate v1 scope cut; polling
     * (`PollEbayOrdersJob`) is the only sync path.
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        // Intentionally empty.
    }

    /**
     * eBay has no webhook ingress in this v1 scope — always null, mirrors
     * `registerWebhooks()`'s no-op.
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        return null;
    }

    /**
     * Fetches the order's own line items first (eBay's shipping_fulfillment
     * endpoint requires an explicit lineItems array with quantities — there
     * is no "fulfill the whole order" shortcut). Fulfills every line item
     * at its full ordered quantity — partial-shipment UI isn't built yet,
     * same scope cut as Shopify's single-fulfillment-order assumption.
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $connection = $order->connection;

        $orderResponse = $this->http($connection)->get("/sell/fulfillment/v1/order/{$order->external_id}");

        if ($orderResponse->failed()) {
            return ActionResult::failure('Could not look up the eBay order.');
        }

        /** @var array<int, array<string, mixed>> $lineItems */
        $lineItems = (array) $orderResponse->json('lineItems', []);

        if ($lineItems === []) {
            return ActionResult::failure('This eBay order has no line items to fulfill.');
        }

        $response = $this->http($connection)->post("/sell/fulfillment/v1/order/{$order->external_id}/shipping_fulfillment", [
            'lineItems' => collect($lineItems)->map(fn (array $item) => [
                'lineItemId' => $item['lineItemId'],
                'quantity' => $item['quantity'] ?? 1,
            ])->all(),
            'shippedDate' => now()->toIso8601String(),
            // eBay expects one of its own carrier-code enum values; ours is
            // free text from the merchant, passed through best-effort
            // uppercased rather than mapped against a table we don't have —
            // verify against a real sandbox order before relying on this.
            'shippingCarrierCode' => $data->carrier !== null ? strtoupper($data->carrier) : 'OTHER',
            'trackingNumber' => $data->trackingNumber,
        ]);

        if ($response->failed()) {
            return ActionResult::failure('eBay rejected the fulfillment.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Order marked fulfilled.');
    }

    /**
     * Uses the Fulfillment API's seller-initiated `issue_refund` endpoint
     * (order-level amount, not line-item detail) — matches our amount-based
     * `RefundData`, same shape as Shopify/WooCommerce's refund.
     */
    public function refund(Order $order, RefundData $data): ActionResult
    {
        $amount = $data->amount ?? (float) $order->total;

        $response = $this->http($order->connection)->post("/sell/fulfillment/v1/order/{$order->external_id}/issue_refund", [
            'reasonForRefund' => 'BUYER_RETURNED_ITEM',
            'comment' => $data->reason,
            'orderLevelRefundAmount' => [
                'value' => (string) $amount,
                'currency' => $order->currency,
            ],
        ]);

        if ($response->failed()) {
            return ActionResult::failure('eBay rejected the refund.');
        }

        $isFullRefund = $data->amount === null || $data->amount >= $order->total;

        $order->update([
            'status' => Order::STATUS_REFUNDED,
            'payment_status' => $isFullRefund ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PARTIALLY_REFUNDED,
            'check_at' => null,
        ]);

        return ActionResult::success('Refund issued.');
    }

    /**
     * Uses the Post-Order API v2 cancellation endpoint. Unverified against
     * a real sandbox order as of this writing — eBay's Post-Order API
     * payload shapes are less consistently documented than the Fulfillment
     * API; confirm this exact request/response shape against a live
     * sandbox call before relying on it in production (same "verify at
     * build time" caveat flagged for eBay's Trading API elsewhere).
     */
    public function cancel(Order $order, ?string $reason): ActionResult
    {
        $response = $this->postOrderHttp($order->connection)->post('/post-order/v2/cancellation', [
            'legacyOrderId' => $order->external_id,
            'cancelReason' => 'OUT_OF_STOCK_OR_CANNOT_FULFILL',
        ]);

        if ($response->failed()) {
            return ActionResult::failure('eBay rejected the cancellation.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);

        return ActionResult::success('Order cancelled.');
    }

    /**
     * eBay's flagship inbox channel (Plan §4.5/§7.3: "best messaging API of
     * the five" — full buyer-seller conversation support). Unlike the rest
     * of this adapter (REST Sell APIs), this is the legacy XML Trading API
     * — isolated here per the class docblock's own "mixed REST + legacy
     * XML... isolate legacy calls inside the adapter" gotcha.
     *
     * Requires the thread to carry the buyer's eBay username and the
     * listing's legacy `ItemID` (`external_buyer_username`/
     * `external_item_id`, captured at order-ingest time by
     * `EbayOrderMapper`) — without both there's no addressable eBay
     * conversation to reply into.
     *
     * Authenticates via IAF — eBay's bridge letting a modern OAuth access
     * token stand in for the Trading API's old separate auth token, so no
     * extra credential is needed beyond what `EbayAdapter` already stores.
     */
    public function sendMessage(InboxThread $thread, string $body): ActionResult
    {
        $buyerUsername = $thread->external_buyer_username;
        $itemId = $thread->external_item_id;

        if ($buyerUsername === null || $itemId === null) {
            return ActionResult::failure('Missing the eBay buyer username or item ID for this thread — cannot send a member message.');
        }

        $response = $this->tradingHttp($thread->connection, self::TRADING_CALL_ADD_MEMBER_MESSAGE)
            ->withBody($this->buildMemberMessageXml($itemId, $buyerUsername, $body), 'text/xml')
            ->post($this->tradingApiUrl());

        // The Trading API's own business-level <Ack> (Success/Failure/
        // Warning) lives in the XML body regardless of HTTP status — unlike
        // the REST calls above, checking $response->failed() alone isn't
        // enough here.
        if ($response->failed() || ! in_array($this->parseTradingAck($response->body()), ['Success', 'Warning'], true)) {
            return ActionResult::failure('eBay rejected the message.');
        }

        return ActionResult::success('Message sent to buyer.');
    }

    /**
     * Inbound half of the same channel (Plan §4.5) — fetches buyer member
     * messages received since `$since` via the Trading API's
     * `GetMemberMessages` call, for `PollEbayMessagesJob` to land into the
     * unified inbox. `FolderID` 0 is the Trading API's "Inbox" (received)
     * folder — verify this exact filter combination against a real sandbox
     * account before relying on it in production, same "confirm at build
     * time" caveat flagged for the Post-Order cancellation call above.
     *
     * @return array<int, array{external_id: string, item_id: string, buyer_username: string, body: string, created_at: CarbonInterface}>
     */
    public function fetchMemberMessages(StoreConnection $connection, CarbonInterface $since): array
    {
        $response = $this->tradingHttp($connection, self::TRADING_CALL_GET_MEMBER_MESSAGES)
            ->withBody($this->buildGetMemberMessagesXml($since), 'text/xml')
            ->post($this->tradingApiUrl());

        if ($response->failed()) {
            return [];
        }

        return $this->parseMemberMessages($response->body());
    }

    /**
     * Polls Trading API `GetFeedback` for feedback received as a seller
     * (Plan §7.3: "poll feedback via Trading API for negative-feedback
     * alerts"), pre-filtered to just `Negative` comments. Maps into the
     * same shape `PollWooReviewsJob` writes into `reviews` (Plan §4.4's
     * `negative_review` trigger) — rating is always 1 here since only
     * negative feedback is ever returned, so `CheckNegativeReviewAction`
     * needs no eBay-specific branch. `GetFeedback` has no start/end-time
     * filter parameter (unlike `GetMemberMessages` above) — same "fetch
     * the latest page, no cursor, dedupe on external_id" shape as
     * `PollWooReviewsJob` instead, deliberately not the cursor-based shape
     * `fetchMemberMessages()`/`PollEbayMessagesJob` use.
     *
     * @return array<int, array{external_id: string, item_id: ?string, rating: int, reviewer_name: string, content: string, created_at: CarbonInterface}>
     */
    public function fetchNegativeFeedback(StoreConnection $connection): array
    {
        $response = $this->tradingHttp($connection, self::TRADING_CALL_GET_FEEDBACK)
            ->withBody($this->buildGetFeedbackXml(), 'text/xml')
            ->post($this->tradingApiUrl());

        // One real outbound call. eBay's ~5k/day budget (Plan §7.3) is
        // actually per-API (Fulfillment/Inventory/Trading each get their
        // own allowance), but this counter aggregates every eBay call
        // under one `ebay` bucket compared against a single 5k figure —
        // a deliberately conservative simplification (see
        // `GetOpsHealthSnapshotAction::apiQuotaUsage()`'s own note) rather
        // than three separate counters for three budgets we'd otherwise
        // never come close to individually.
        ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_EBAY);

        if ($response->failed()) {
            return [];
        }

        return $this->parseNegativeFeedback($response->body());
    }

    /**
     * Polls the Sell Inventory API for current stock levels (Plan §7.8's
     * eBay "Inventory update: ✅", feeding the `low_stock` trigger). No
     * inventory webhook exists for eBay (same v1 scope cut as order
     * webhooks, see class docblock) — polling-only. Maps into the same
     * shape `PollWooProductsJob` writes into `products`, keyed by SKU since
     * the Inventory API is itself SKU-addressed (unlike Shopify's
     * variant-id keying) — so `CheckLowStockAction` needs no eBay-specific
     * branch either.
     *
     * @return array<int, array{external_id: string, sku: ?string, title: string, stock_quantity: ?int}>
     */
    public function fetchInventoryItems(StoreConnection $connection): array
    {
        $items = [];
        $limit = 100;
        $offset = 0;
        $total = null;

        do {
            $response = $this->http($connection)->get('/sell/inventory/v1/inventory_item', [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            // One real outbound call per page — see the aggregation note
            // on the `fetchNegativeFeedback()` hook above.
            ApiQuotaTracker::recordCall(StoreConnection::PLATFORM_EBAY);

            if ($response->failed()) {
                break;
            }

            /** @var array<int, array<string, mixed>> $page */
            $page = (array) $response->json('inventoryItems', []);
            $total ??= (int) ($response->json('total') ?? count($page));

            foreach ($page as $raw) {
                $sku = (string) ($raw['sku'] ?? '');

                if ($sku === '') {
                    continue;
                }

                $items[] = [
                    'external_id' => $sku,
                    'sku' => $sku,
                    'title' => (string) (data_get($raw, 'product.title') ?? $sku),
                    'stock_quantity' => data_get($raw, 'availability.shipToLocationAvailability.quantity'),
                ];
            }

            $offset += $limit;
        } while (count($page) === $limit && $offset < $total);

        return $items;
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            realtimeOrders: true,
            fulfillTracking: true,
            refunds: true,
            cancel: true,
            messagingMode: 'full',
            inventoryUpdate: true,
            reviewsFeedback: true,
        );
    }

    private function isSandbox(): bool
    {
        return config('services.ebay.env', 'sandbox') === 'sandbox';
    }

    private function apiBaseUrl(): string
    {
        return $this->isSandbox() ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
    }

    private function tokenUrl(): string
    {
        return "{$this->apiBaseUrl()}/identity/v1/oauth2/token";
    }

    private function tokenClient(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.ebay.app_id'), (string) config('services.ebay.cert_id'));
    }

    private function http(StoreConnection $connection): PendingRequest
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        return Http::baseUrl($this->apiBaseUrl())
            ->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson();
    }

    private function postOrderHttp(StoreConnection $connection): PendingRequest
    {
        return $this->http($connection);
    }

    private function tradingApiUrl(): string
    {
        return $this->isSandbox() ? 'https://api.sandbox.ebay.com/ws/api.dll' : 'https://api.ebay.com/ws/api.dll';
    }

    private function tradingHttp(StoreConnection $connection, string $callName): PendingRequest
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        return Http::withHeaders([
            'X-EBAY-API-COMPATIBILITY-LEVEL' => self::TRADING_API_COMPATIBILITY_LEVEL,
            'X-EBAY-API-CALL-NAME' => $callName,
            'X-EBAY-API-SITEID' => '0',
            'X-EBAY-API-IAF-TOKEN' => (string) ($credentials['access_token'] ?? ''),
        ]);
    }

    private function buildMemberMessageXml(string $itemId, string $buyerUsername, string $body): string
    {
        $itemId = $this->escapeXml($itemId);
        $buyerUsername = $this->escapeXml($buyerUsername);
        $body = $this->escapeXml($body);

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <AddMemberMessageAAQToPartnerRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <ItemID>{$itemId}</ItemID>
            <MemberMessage>
                <Body>{$body}</Body>
                <QuestionType>General</QuestionType>
                <RecipientID>{$buyerUsername}</RecipientID>
            </MemberMessage>
        </AddMemberMessageAAQToPartnerRequest>
        XML;
    }

    private function buildGetMemberMessagesXml(CarbonInterface $since): string
    {
        $startTime = $this->escapeXml($since->toIso8601String());
        $endTime = $this->escapeXml(now()->toIso8601String());

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <GetMemberMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <MailMessageType>All</MailMessageType>
            <FolderID>0</FolderID>
            <StartCreationTime>{$startTime}</StartCreationTime>
            <EndCreationTime>{$endTime}</EndCreationTime>
            <DetailLevel>ReturnMessages</DetailLevel>
        </GetMemberMessagesRequest>
        XML;
    }

    private function buildGetFeedbackXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <GetFeedbackRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <FeedbackType>FeedbackReceivedAsSeller</FeedbackType>
            <DetailLevel>ReturnAll</DetailLevel>
            <Pagination>
                <EntriesPerPage>100</EntriesPerPage>
                <PageNumber>1</PageNumber>
            </Pagination>
        </GetFeedbackRequest>
        XML;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Namespace-agnostic on purpose: the Trading API's own default
     * namespace (`urn:ebay:apis:eBLBaseComponents`) makes plain
     * `$xml->Ack` lookups unreliable across SimpleXML versions/parsers, so
     * this reads the raw `<Ack>` element by local name instead of trusting
     * namespace resolution.
     */
    private function parseTradingAck(string $bodyXml): ?string
    {
        $xml = $this->parseTradingXml($bodyXml);

        if ($xml === null) {
            return null;
        }

        $nodes = $xml->xpath("//*[local-name()='Ack']") ?: [];

        return isset($nodes[0]) ? (string) $nodes[0] : null;
    }

    /**
     * @return array<int, array{external_id: string, item_id: string, buyer_username: string, body: string, created_at: CarbonInterface}>
     */
    private function parseMemberMessages(string $bodyXml): array
    {
        $xml = $this->parseTradingXml($bodyXml);

        if ($xml === null) {
            return [];
        }

        $nodes = $xml->xpath("//*[local-name()='MemberMessage']") ?: [];

        return collect($nodes)
            ->map(function ($node): array {
                $messageId = (string) ($node->MessageID ?? '');
                $sender = (string) ($node->Sender ?? '');
                $itemId = (string) ($node->ItemID ?? '');
                $body = (string) ($node->Body ?? '');
                $creationDate = (string) ($node->CreationDate ?? '');

                return [
                    'external_id' => $messageId,
                    'item_id' => $itemId,
                    'buyer_username' => $sender,
                    'body' => $body,
                    'created_at' => $creationDate !== '' ? Carbon::parse($creationDate) : now(),
                ];
            })
            ->filter(fn (array $message) => $message['external_id'] !== '' && $message['buyer_username'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{external_id: string, item_id: ?string, rating: int, reviewer_name: string, content: string, created_at: CarbonInterface}>
     */
    private function parseNegativeFeedback(string $bodyXml): array
    {
        $xml = $this->parseTradingXml($bodyXml);

        if ($xml === null) {
            return [];
        }

        $nodes = $xml->xpath("//*[local-name()='FeedbackDetail']") ?: [];

        return collect($nodes)
            ->filter(fn ($node) => (string) ($node->CommentType ?? '') === 'Negative')
            ->map(function ($node): array {
                $feedbackId = (string) ($node->FeedbackID ?? '');
                $itemId = (string) ($node->ItemID ?? '');
                $commentTime = (string) ($node->CommentTime ?? '');

                return [
                    'external_id' => $feedbackId,
                    'item_id' => $itemId !== '' ? $itemId : null,
                    'rating' => 1,
                    'reviewer_name' => (string) ($node->CommentingUser ?? ''),
                    'content' => (string) ($node->CommentText ?? ''),
                    'created_at' => $commentTime !== '' ? Carbon::parse($commentTime) : now(),
                ];
            })
            ->filter(fn (array $feedback) => $feedback['external_id'] !== '')
            ->values()
            ->all();
    }

    private function parseTradingXml(string $bodyXml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($bodyXml);
        libxml_use_internal_errors($previous);

        return $xml !== false ? $xml : null;
    }

    /**
     * Unlike `AmazonAdapter`/`TikTokAdapter`, this wasn't guarded until
     * this pass — `authorizationUrl()` would silently build a URL with an
     * empty `client_id`/`redirect_uri` instead of failing cleanly when the
     * Developer Portal app credentials aren't configured (Plan §15.2).
     * Same pattern as those two now.
     */
    private function assertConfigured(): void
    {
        if (
            ! is_string(config('services.ebay.app_id')) || config('services.ebay.app_id') === ''
            || ! is_string(config('services.ebay.cert_id')) || config('services.ebay.cert_id') === ''
            || ! is_string(config('services.ebay.ru_name')) || config('services.ebay.ru_name') === ''
        ) {
            throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_EBAY);
        }
    }
}
