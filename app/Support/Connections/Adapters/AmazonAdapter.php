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
 * Pending Amazon SP-API developer vetting — takes weeks, file earliest of
 * all platforms even though it ships last (Plan §7.5, §15.2).
 */
class AmazonAdapter implements ChannelAdapter
{
    public function connect(ConnectRequest $request): StoreConnection
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function refreshAuth(StoreConnection $connection): void
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function registerWebhooks(StoreConnection $connection): void
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function parseWebhook(StoreConnection $connection, Request $request): ?array
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function fulfill(Order $order, FulfillmentData $data): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function refund(Order $order, RefundData $data): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function cancel(Order $order, ?string $reason): ActionResult
    {
        throw AdapterNotReadyException::forPlatform(StoreConnection::PLATFORM_AMAZON);
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            realtimeOrders: true,
            fulfillTracking: true,
            refunds: true,
            cancel: true,
            messagingMode: 'template',
            inventoryUpdate: true,
            reviewsFeedback: true,
        );
    }
}
