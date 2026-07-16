<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ProcessRevenueCatEventAction;
use App\Actions\Orders\IngestOrderAction;
use App\Jobs\RuleEvaluationJob;
use App\Models\Order;
use App\Models\RevenueCatEvent;
use App\Models\Rule;
use App\Models\StoreConnection;
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
        IngestOrderAction $ingestOrder,
    ): JsonResponse {
        if ($connection->platform !== StoreConnection::PLATFORM_WOO) {
            return response()->json(['error' => 'not found'], 404);
        }

        $adapter = $adapters->driver(StoreConnection::PLATFORM_WOO);
        $parsed = $adapter->parseWebhook($connection, $request);

        if ($parsed === null) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        if ($parsed['type'] === 'order.deleted') {
            if ($parsed['external_id'] !== null) {
                $order = Order::query()
                    ->where('connection_id', $connection->id)
                    ->where('external_id', $parsed['external_id'])
                    ->first();

                if ($order !== null && $order->status !== Order::STATUS_CANCELLED) {
                    $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);
                    RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_ORDER_CANCELLED)->afterCommit();
                }
            }

            return response()->json(['status' => 'ok']);
        }

        if ($parsed['order'] !== null) {
            $ingestOrder->handle($connection, $parsed['order']);
        }

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
}
