<?php

namespace App\Support\Connections\Adapters;

use App\Contracts\ChannelAdapter;
use App\Jobs\RuleEvaluationJob;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Support\Connections\ActionResult;
use App\Support\Connections\Adapters\Woo\WooOrderMapper;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use App\Support\Orders\NormalizedOrder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WooCommerce needs no OAuth app approval (Plan §7.2) — the merchant hands
 * over a consumer key/secret directly, so this is the one adapter that's
 * fully real: connect() validates the credentials against the live store
 * before ever saving them, registerWebhooks() creates real Woo webhook
 * subscriptions, and parseWebhook() verifies + normalizes real inbound
 * payloads.
 */
class WooCommerceAdapter implements ChannelAdapter
{
    private const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'order.deleted'];

    public function __construct(
        private readonly WooOrderMapper $orderMapper,
    ) {}

    public function connect(ConnectRequest $request): StoreConnection
    {
        $storeUrl = rtrim((string) $request->credentials['store_url'], '/');
        $consumerKey = (string) $request->credentials['consumer_key'];
        $consumerSecret = (string) $request->credentials['consumer_secret'];

        $validation = Http::withBasicAuth($consumerKey, $consumerSecret)
            ->get("{$storeUrl}/wp-json/wc/v3/orders", ['per_page' => 1]);

        if ($validation->failed()) {
            throw ValidationException::withMessages([
                'credentials' => 'Could not connect to this WooCommerce store. Check the store URL and API keys.',
            ]);
        }

        $connection = StoreConnection::query()->create([
            'team_id' => $request->team->id,
            'platform' => StoreConnection::PLATFORM_WOO,
            'name' => $request->name,
            'credentials' => [
                'store_url' => $storeUrl,
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ],
            'status' => StoreConnection::STATUS_ACTIVE,
        ]);

        $this->registerWebhooks($connection);

        return $connection;
    }

    public function refreshAuth(StoreConnection $connection): void
    {
        // Consumer key/secret pairs don't expire — nothing to refresh.
    }

    /**
     * Resilient per-topic: a partial failure (e.g. one topic rejected)
     * still leaves the others registered — the reconciliation poller
     * covers whatever webhooks miss regardless (§7.2 gotcha).
     */
    public function registerWebhooks(StoreConnection $connection): void
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $secret = Str::random(40);
        $registered = [];
        $failedAny = false;

        foreach (self::WEBHOOK_TOPICS as $topic) {
            $response = Http::withBasicAuth((string) $credentials['consumer_key'], (string) $credentials['consumer_secret'])
                ->post($credentials['store_url'].'/wp-json/wc/v3/webhooks', [
                    'name' => "OrderPulse {$topic}",
                    'topic' => $topic,
                    'delivery_url' => route('hooks.woo', ['connection' => $connection->id]),
                    'secret' => $secret,
                ]);

            if ($response->successful()) {
                $registered[$topic] = $response->json('id');
            } else {
                $failedAny = true;
            }
        }

        $connection->update([
            'credentials' => [...$credentials, 'webhook_secret' => $secret, 'webhook_ids' => $registered],
            'webhook_status' => $failedAny ? 'partial' : 'active',
        ]);
    }

    /**
     * @return array{type: string, external_id: ?string, order: ?NormalizedOrder}|null
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];
        $secret = $credentials['webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '' || ! $this->hasValidSignature($request, $secret)) {
            return null;
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic', '');
        $payload = (array) $request->json()->all();

        if ($topic === 'order.deleted') {
            return [
                'type' => 'order.deleted',
                'external_id' => isset($payload['id']) ? (string) $payload['id'] : null,
                'order' => null,
            ];
        }

        if (in_array($topic, ['order.created', 'order.updated'], true) && isset($payload['id'])) {
            return [
                'type' => $topic,
                'external_id' => (string) $payload['id'],
                'order' => $this->orderMapper->map($payload),
            ];
        }

        return null;
    }

    private function hasValidSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-WC-Webhook-Signature');

        if ($signature === null) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Marks the order completed and records tracking as our own order
     * meta. Woo has no single universal tracking field — third-party
     * shipment-tracking plugins each use their own meta schema — so we
     * can't guarantee this surfaces in every merchant's WooCommerce admin,
     * but the status change and our own recorded tracking data are real
     * and always work.
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        $response = $this->httpFor($order->connection)
            ->put("{$this->storeUrl($order->connection)}/wp-json/wc/v3/orders/{$order->external_id}", [
                'status' => 'completed',
                'meta_data' => [
                    ['key' => '_stockbeat_tracking_number', 'value' => $data->trackingNumber],
                    ['key' => '_stockbeat_tracking_carrier', 'value' => $data->carrier ?? ''],
                ],
            ]);

        if ($response->failed()) {
            return ActionResult::failure('WooCommerce rejected the fulfillment update.');
        }

        $order->update([
            'status' => Order::STATUS_SHIPPED,
            'fulfillment_status' => Order::FULFILLMENT_FULFILLED,
            'check_at' => null,
        ]);

        return ActionResult::success('Order marked fulfilled.');
    }

    public function refund(Order $order, RefundData $data): ActionResult
    {
        $payload = array_filter([
            'amount' => $data->amount !== null ? (string) $data->amount : null,
            'reason' => $data->reason,
        ], fn ($value) => $value !== null);

        $response = $this->httpFor($order->connection)
            ->post("{$this->storeUrl($order->connection)}/wp-json/wc/v3/orders/{$order->external_id}/refunds", $payload);

        if ($response->failed()) {
            return ActionResult::failure('WooCommerce rejected the refund.');
        }

        $isFullRefund = $data->amount === null || $data->amount >= $order->total;

        $order->update([
            'status' => Order::STATUS_REFUNDED,
            'payment_status' => $isFullRefund ? Order::PAYMENT_REFUNDED : Order::PAYMENT_PARTIALLY_REFUNDED,
            'check_at' => null,
        ]);

        // Unlike order_cancelled/refund_requested (deliberately not fired
        // from our own quick actions — the merchant already knows they just
        // did it), refund_spike is a volume signal the merchant may not
        // notice while processing refunds one at a time, so it fires
        // regardless of whether the refund came from a webhook or here.
        RuleEvaluationJob::dispatch($order->id, Rule::TRIGGER_REFUND_SPIKE);

        return ActionResult::success('Refund issued.');
    }

    public function cancel(Order $order, ?string $reason): ActionResult
    {
        $response = $this->httpFor($order->connection)
            ->put("{$this->storeUrl($order->connection)}/wp-json/wc/v3/orders/{$order->external_id}", [
                'status' => 'cancelled',
            ]);

        if ($response->failed()) {
            return ActionResult::failure('WooCommerce rejected the cancellation.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'check_at' => null]);

        return ActionResult::success('Order cancelled.');
    }

    private function httpFor(StoreConnection $connection): PendingRequest
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        return Http::withBasicAuth((string) $credentials['consumer_key'], (string) $credentials['consumer_secret']);
    }

    private function storeUrl(StoreConnection $connection): string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $connection->credentials ?? [];

        return rtrim((string) $credentials['store_url'], '/');
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
}
