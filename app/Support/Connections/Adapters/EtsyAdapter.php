<?php

namespace App\Support\Connections\Adapters;

use App\Contracts\ChannelAdapter;
use App\Exceptions\Connections\AdapterNotReadyException;
use App\Models\Order;
use App\Models\StoreConnection;
use App\Support\Connections\ActionResult;
use App\Support\Connections\CapabilitySet;
use App\Support\Connections\ConnectRequest;
use App\Support\Connections\FulfillmentData;
use App\Support\Connections\RefundData;
use Illuminate\Http\Request;

/**
 * Pending Etsy Open API v3 commercial-access approval (Plan §7.4).
 */
class EtsyAdapter implements ChannelAdapter
{
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_ETSY);
    }

    public function refreshAuth(StoreConnection $connection): void
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_ETSY);
    }

    public function registerWebhooks(StoreConnection $connection): void
    {
        // No-op by design: Etsy has no webhooks, polling only (§7.4).
    }

    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        throw AdapterNotReadyException::forFeature(
            StoreConnection::PLATFORM_ETSY,
            'Etsy has no webhooks — orders sync via polling only',
        );
    }

    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_ETSY);
    }

    public function refund(Order $order, RefundData $data): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_ETSY);
    }

    public function cancel(Order $order, ?string $reason): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_ETSY);
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            realtimeOrders: false,
            fulfillTracking: true,
            refunds: true,
            cancel: true,
            messagingMode: 'approval_gated',
            inventoryUpdate: true,
            reviewsFeedback: true,
        );
    }
}
