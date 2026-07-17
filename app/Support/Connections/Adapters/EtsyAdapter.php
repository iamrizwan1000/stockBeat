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
use App\Support\Connections\OAuthState;
use App\Support\Connections\RefundData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Real Etsy adapter (Plan §7.4) — OAuth 2.0 + PKCE via a "Your Apps"
 * keystring/shared-secret, gated behind Etsy's commercial-access review
 * (filed separately; this code works once that's approved). PKCE's
 * `code_verifier` is derived deterministically from the OAuth `state`
 * nonce (`deriveCodeVerifier()`) rather than persisted anywhere extra —
 * both `authorizationUrl()` and `completeConnection()` recompute the same
 * value from the same nonce, so nothing needs a session/cache round trip.
 */
class EtsyAdapter implements ChannelAdapter, OAuthChannelAdapter
{
    private const SCOPES = 'transactions_r transactions_w listings_r shops_r feedback_r';

    private const API_BASE = 'https://api.etsy.com/v3/application';

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function authorizationUrl(array $startCredentials, string $state): string
    {
        $decoded = OAuthState::decode($state);
        $nonce = $decoded !== null ? $decoded->nonce : $state;
        $verifier = $this->deriveCodeVerifier($nonce);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return 'https://www.etsy.com/oauth/connect?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.etsy.keystring'),
            'redirect_uri' => route('hooks.etsy.oauth-callback'),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param  array<string, mixed>  $startCredentials
     */
    public function completeConnection(Team $team, string $name, array $startCredentials, string $nonce, Request $callback): StoreConnection
    {
        $code = (string) $callback->query('code', '');

        if ($code === '') {
            throw ValidationException::withMessages(['etsy' => 'Missing authorization code.']);
        }

        $verifier = $this->deriveCodeVerifier($nonce);

        $tokenResponse = Http::asForm()->post('https://api.etsy.com/v3/public/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.etsy.keystring'),
            'redirect_uri' => route('hooks.etsy.oauth-callback'),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        $accessToken = $tokenResponse->json('access_token');
        $refreshToken = $tokenResponse->json('refresh_token');

        if ($tokenResponse->failed() || ! is_string($accessToken) || ! is_string($refreshToken)) {
            throw ValidationException::withMessages(['etsy' => 'Could not complete the Etsy connection.']);
        }

        // Etsy's access token is literally "{user_id}.{opaque}" — the shop
        // owner's numeric user id is the prefix before the first dot.
        $userId = strtok($accessToken, '.');

        $shopsResponse = Http::withHeaders(['x-api-key' => config('services.etsy.keystring')])
            ->withToken($accessToken)
            ->acceptJson()
            ->get(self::API_BASE."/users/{$userId}/shops");

        $shopId = $shopsResponse->json('shop_id');

        if ($shopsResponse->failed() || $shopId === null) {
            throw ValidationException::withMessages(['etsy' => 'Could not look up your Etsy shop.']);
        }

        $expiresIn = (int) $tokenResponse->json('expires_in', 3600);

        return StoreConnection::query()->create([
            'team_id' => $team->id,
            'platform' => StoreConnection::PLATFORM_ETSY,
            'name' => $name,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                'shop_id' => $shopId,
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);
    }

    /**
     * Not used — Etsy only ever connects via the OAuth+PKCE flow above,
     * same reasoning as Shopify/eBay's `connect()`.
     */
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw new LogicException('EtsyAdapter connects via OAuth — use StartOAuthConnectionAction, not connect().');
    }

    public function refreshAuth(StoreConnection $connection): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $response = Http::asForm()->post('https://api.etsy.com/v3/public/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.etsy.keystring'),
            'refresh_token' => $refreshToken,
        ]);

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            $connection->update(['status' => StoreConnection::STATUS_NEEDS_REAUTH]);

            return;
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        $newRefreshToken = $response->json('refresh_token', $refreshToken);

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
     * No-op by design: Etsy has no webhooks, polling only (§7.4).
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        // Intentionally empty.
    }

    /**
     * Etsy has no webhook ingress — always null, mirrors
     * `registerWebhooks()`'s no-op (same convention as EbayAdapter).
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        return null;
    }

    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $connection = $order->connection;
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $shopId = $credentials['shop_id'] ?? null;

        $response = $this->http($connection)->post(self::API_BASE."/shops/{$shopId}/receipts/{$order->external_id}/tracking", [
            'tracking_code' => $data->trackingNumber,
            'carrier_name' => $data->carrier,
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Etsy rejected the tracking update.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Order marked fulfilled.');
    }

    /**
     * Unverified against a live Etsy shop as of this writing — Plan §7.4
     * describes refunds as going through Etsy's "Ledger/Payments
     * endpoints" without a single canonical "create refund" call
     * documented as clearly as Shopify/eBay's. Confirm this exact
     * endpoint/payload shape against a real sandbox-equivalent Etsy shop
     * (Etsy has no true sandbox — see the onboarding notes) before relying
     * on it in production.
     */
    public function refund(Order $order, RefundData $data): ActionResult
    {
        $connection = $order->connection;
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $shopId = $credentials['shop_id'] ?? null;
        $amount = $data->amount ?? (float) $order->total;

        $response = $this->http($connection)->post(self::API_BASE."/shops/{$shopId}/receipts/{$order->external_id}/refunds", [
            'amount' => $amount,
            'reason' => $data->reason,
        ]);

        if ($response->failed()) {
            return ActionResult::failure('Etsy rejected the refund.');
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
     * Etsy doesn't expose a direct seller-initiated "cancel this receipt"
     * API call the way Shopify/eBay do — Plan §7.4 itself flags
     * cancellations as "seller-initiated request flows, more limited"
     * (§7.8's matrix marks this ⚠️, not ✅, for Etsy specifically).
     * `capabilities()->cancel` is false so `CancelOrderAction` never
     * reaches here in normal use; this exists only as a defensive
     * fallback if that gate were ever bypassed.
     */
    public function cancel(Order $order, ?string $reason): ActionResult
    {
        return ActionResult::failure('Etsy does not support direct order cancellation via API — this must be handled as a seller-initiated request through Etsy\'s own seller dashboard.');
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            realtimeOrders: false,
            fulfillTracking: true,
            refunds: true,
            cancel: false,
            messagingMode: 'approval_gated',
            inventoryUpdate: true,
            reviewsFeedback: true,
        );
    }

    private function deriveCodeVerifier(string $nonce): string
    {
        return hash_hmac('sha256', $nonce, config('app.key'));
    }

    private function http(StoreConnection $connection): PendingRequest
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        return Http::withHeaders(['x-api-key' => config('services.etsy.keystring')])
            ->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson();
    }
}
