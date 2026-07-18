<?php

namespace App\Contracts;

use App\Models\InboxThread;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\ActionResult;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Http\Request;

/**
 * Every sales channel implements this contract (Plan §8.3): the app stays
 * unified while honoring per-platform limits, and a new channel is a new
 * adapter with zero core changes.
 *
 * The full §8.3 contract also defines fetchOrders — still deferred (no
 * caller type-hints against it yet; every adapter currently gets its orders
 * via its own polling job/webhook parser instead of a shared entry point).
 * `sendMessage` below is no longer deferred now that the Inbox module
 * exists.
 */
interface ChannelAdapter
{
    public function connect(ConnectRequest $request): StoreConnection;

    public function refreshAuth(StoreConnection $connection): void;

    /**
     * No-op for platforms without a webhook mechanism (e.g. Etsy).
     */
    public function registerWebhooks(StoreConnection $connection): void;

    /**
     * Verifies the platform's signature using the connection's own secret,
     * then parses the payload. A connection is required (not just the
     * request) because signature verification is per-connection.
     *
     * @return array<string, mixed>|null null when the signature is invalid
     *                                   or the payload isn't a recognized
     *                                   event for this platform.
     */
    public function parseWebhook(StoreConnection $connection, Request $request): ?array;

    /**
     * Marks the order fulfilled on the platform with tracking info, per
     * `capabilities()->fulfillTracking`.
     */
    public function fulfill(Order $order, FulfillmentData $data): ActionResult;

    /**
     * Issues a refund on the platform, per `capabilities()->refunds`.
     */
    public function refund(Order $order, RefundData $data): ActionResult;

    /**
     * Cancels the order on the platform, per `capabilities()->cancel`.
     */
    public function cancel(Order $order, ?string $reason): ActionResult;

    /**
     * Sends an outbound reply on the thread's native channel, per
     * `capabilities()->messagingMode` (Plan §4.5/§7.8):
     *  - `'full'` (eBay): a real Trading API member message.
     *  - `'approval_gated'` (Etsy): real request-building, but throws
     *    `AdapterNotReadyException` until the connection's conversations
     *    approval is granted.
     *  - `'email'` (Shopify/Woo): not implemented here — these platforms
     *    have no native chat API, so `SendInboxMessageAction` handles them
     *    entirely through its own email path and never reaches this method;
     *    both adapters throw if called directly.
     *  - `'template'` (Amazon): the rest of `AmazonAdapter` is real and
     *    config-gated like every other adapter, but its Messaging API
     *    request-building is a deliberate deferral (a separate task) —
     *    always throws `AdapterNotReadyException` regardless of config.
     */
    public function sendMessage(InboxThread $thread, string $body): ActionResult;

    public function capabilities(): CapabilitySet;
}
