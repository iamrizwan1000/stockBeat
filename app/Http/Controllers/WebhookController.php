<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Actions\Connections\ProcessShopifyGdprRequestAction;
use App\Actions\Inbox\ParseInboundEmailTokenAction;
use App\Actions\Inbox\ReceiveInboxReplyAction;
use App\Actions\Support\ReceiveInboundEmailReplyAction;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Jobs\ProcessTikTokWebhookJob;
use App\Jobs\ProcessWooWebhookJob;
use App\Models\InboxThread;
use App\Models\RevenueCatEvent;
use App\Models\StoreConnection;
use App\Models\SupportThread;
use App\Models\User;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public webhook ingress (Plan §10, §17.7: "webhook ingestion never down —
 * separate ingress"). Deliberately outside `/api/v1` — no Sanctum auth —
 * each platform's own signature scheme is the security boundary instead.
 */
class WebhookController extends Controller
{
    public function woo(
        Request $request,
        StoreConnection $connection,
        ChannelAdapterManager $adapters,
    ): JsonResponse {
        if ($connection->platform !== StoreConnection::PLATFORM_WOO) {
            return response()->json(['error' => 'not found'], 404);
        }

        $adapter = $adapters->driver(StoreConnection::PLATFORM_WOO);
        $parsed = $adapter->parseWebhook($connection, $request);

        if ($parsed === null) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        // Signature verification needs the raw Request (above), but nothing past
        // this point does — hand off to the `ingest` queue (Plan §15.1) so Woo
        // gets its 200 without waiting on our DB.
        ProcessWooWebhookJob::dispatch($connection->id, $parsed)->onQueue('ingest');

        return response()->json(['status' => 'ok']);
    }

    public function shopify(
        Request $request,
        StoreConnection $connection,
        ChannelAdapterManager $adapters,
    ): JsonResponse {
        if ($connection->platform !== StoreConnection::PLATFORM_SHOPIFY) {
            return response()->json(['error' => 'not found'], 404);
        }

        $adapter = $adapters->driver(StoreConnection::PLATFORM_SHOPIFY);
        $parsed = $adapter->parseWebhook($connection, $request);

        if ($parsed === null) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        ProcessShopifyWebhookJob::dispatch($connection->id, $parsed)->onQueue('ingest');

        return response()->json(['status' => 'ok']);
    }

    /**
     * TikTok Shop order-status-change webhooks (Plan §7.6) — see
     * `TikTokAdapter::parseWebhook()`'s own docblock for why the payload is
     * a thin notification rather than a full order object.
     */
    public function tiktok(
        Request $request,
        StoreConnection $connection,
        ChannelAdapterManager $adapters,
    ): JsonResponse {
        if ($connection->platform !== StoreConnection::PLATFORM_TIKTOK) {
            return response()->json(['error' => 'not found'], 404);
        }

        $adapter = $adapters->driver(StoreConnection::PLATFORM_TIKTOK);
        $parsed = $adapter->parseWebhook($connection, $request);

        if ($parsed === null) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        ProcessTikTokWebhookJob::dispatch($connection->id, $parsed)->onQueue('ingest');

        return response()->json(['status' => 'ok']);
    }

    /**
     * Shopify's mandatory GDPR compliance webhooks (Plan §7.1) — a single
     * global endpoint (registered app-wide via `shopify.app.toml`, not
     * per-connection), signed the same way as order webhooks
     * (`X-Shopify-Hmac-Sha256` over the raw body, app client_secret).
     */
    public function shopifyGdpr(Request $request, ProcessShopifyGdprRequestAction $processGdpr): JsonResponse
    {
        $secret = config('services.shopify.client_secret');
        $signature = $request->header('X-Shopify-Hmac-Sha256');

        if (! is_string($secret) || $secret === '' || $signature === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $payload = (array) $request->json()->all();

        $processGdpr->handle($topic, $payload);

        return response()->json(['status' => 'ok']);
    }

    /**
     * RevenueCat's own webhook auth (Plan §6.1) is a fixed `Authorization`
     * header value configured in its dashboard — there's no per-request
     * signature to verify, so a constant-time compare against our own
     * shared secret is the whole security boundary here.
     */
    public function revenuecat(Request $request, ProcessRevenueCatEventAction $processEvent): JsonResponse
    {
        $expected = config('services.revenuecat.webhook_secret');
        $provided = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || $provided === null || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $request->json('event', []);
        $eventId = (string) ($payload['id'] ?? '');
        $appUserId = $payload['app_user_id'] ?? null;

        if ($eventId === '' || $appUserId === null) {
            return response()->json(['status' => 'ignored']);
        }

        $revenueCatEvent = RevenueCatEvent::query()->firstOrCreate(
            ['event_id' => $eventId],
            ['event_type' => (string) ($payload['type'] ?? ''), 'processed_at' => now()],
        );

        if (! $revenueCatEvent->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate']);
        }

        $team = User::query()->find((int) $appUserId)?->currentTeam();

        if ($team !== null) {
            $processEvent->handle($team, $payload);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Inbound email reply threading (Plan §4.5/§4.9/§7.7), shared between
     * the unified customer inbox and support chat via plus-addressing on
     * the `to` field. Expects an already-parsed payload (`to`/`from`/`text`)
     * — the shape a provider like SES+Lambda, Mailgun, or Postmark's Inbound
     * Parse delivers, not raw MIME (no provider is actually connected yet;
     * this is the boundary once one is). Auth is a shared secret, same
     * pattern as `revenuecat()` — there's no per-request signature to
     * verify without a specific provider chosen.
     */
    public function emailInbound(
        Request $request,
        ParseInboundEmailTokenAction $parseToken,
        ReceiveInboundEmailReplyAction $receiveSupportReply,
        ReceiveInboxReplyAction $receiveInboxReply,
    ): JsonResponse {
        $expected = config('services.inbound_email.webhook_secret');
        $provided = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || $provided === null || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $to = (string) $request->input('to', '');
        $from = (string) $request->input('from', '');
        $body = (string) $request->input('text', '');

        $token = $parseToken->handle($to);

        if ($token === null || $body === '') {
            return response()->json(['status' => 'ignored']);
        }

        if ($token['prefix'] === 'support') {
            $thread = SupportThread::query()->find($token['id']);

            if ($thread !== null) {
                $receiveSupportReply->handle($thread, $from, $body);
            }
        }

        if ($token['prefix'] === 'thread') {
            $thread = InboxThread::query()->find($token['id']);

            if ($thread !== null) {
                $receiveInboxReply->handle($thread, $from, $body);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
