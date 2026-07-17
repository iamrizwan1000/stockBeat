<?php

namespace App\Support\Connections\Adapters;

use App\Contracts\ChannelAdapter;
use App\Contracts\OAuthChannelAdapter;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Models\Team;
use App\Support\Connections\ActionResult;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
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
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
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
}
