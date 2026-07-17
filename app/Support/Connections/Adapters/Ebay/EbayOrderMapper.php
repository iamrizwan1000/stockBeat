<?php

namespace App\Support\Connections\Adapters\Ebay;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw eBay Sell Fulfillment API v1 order object into our
 * platform-agnostic NormalizedOrder (Plan §7.3). eBay masks buyer PII more
 * aggressively than Shopify/Woo: there is no direct buyer email on this
 * resource, so `customerEmail` is always null here — a real platform
 * limitation, not a mapping bug (Plan's own capability matrix doesn't
 * promise email access for eBay).
 */
class EbayOrderMapper
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function map(array $raw): NormalizedOrder
    {
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus($raw);

        $shipTo = $this->shipTo($raw);
        $contactAddress = is_array($shipTo['contactAddress'] ?? null) ? $shipTo['contactAddress'] : [];
        $customerName = $shipTo['fullName'] ?? (is_array($raw['buyer'] ?? null) ? ($raw['buyer']['username'] ?? null) : null);

        $pricing = is_array($raw['pricingSummary'] ?? null) ? $raw['pricingSummary'] : [];
        $total = is_array($pricing['total'] ?? null) ? $pricing['total'] : [];

        /** @var array<int, array<string, mixed>> $lineItems */
        $lineItems = is_array($raw['lineItems'] ?? null) ? $raw['lineItems'] : [];

        return new NormalizedOrder(
            externalId: (string) ($raw['orderId'] ?? $raw['legacyOrderId'] ?? ''),
            orderNumber: '#'.($raw['legacyOrderId'] ?? $raw['orderId'] ?? ''),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($total['currency'] ?? 'USD'),
            total: (float) ($total['value'] ?? 0),
            customerName: is_string($customerName) && $customerName !== '' ? $customerName : null,
            // eBay's Fulfillment API doesn't expose a buyer email on this
            // resource — a real platform limitation (Plan §7.8), not a gap
            // in this mapper.
            customerEmail: null,
            shippingAddress: [
                'line1' => $contactAddress['addressLine1'] ?? null,
                'line2' => $contactAddress['addressLine2'] ?? null,
                'city' => $contactAddress['city'] ?? null,
                'state' => $contactAddress['stateOrProvince'] ?? null,
                'postcode' => $contactAddress['postalCode'] ?? null,
                'country' => $contactAddress['countryCode'] ?? null,
            ],
            placedAt: isset($raw['creationDate']) ? Carbon::parse($raw['creationDate'])->utc() : Carbon::now(),
            shipByAt: null, // handling-time SLA lives on the listing, not the order resource — deliberate v1 scope cut
            tags: [],
            raw: $raw,
            // eBay sandbox is a wholly separate environment from production
            // rather than a per-order test flag like Shopify's — there's no
            // equivalent signal on this resource (same as Woo, §7.2).
            isTest: false,
            items: collect($lineItems)
                ->map(fn (array $item) => $this->mapItem($item))
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function shipTo(array $raw): array
    {
        /** @var array<int, array<string, mixed>> $instructions */
        $instructions = is_array($raw['fulfillmentStartInstructions'] ?? null) ? $raw['fulfillmentStartInstructions'] : [];
        $first = $instructions[0] ?? [];
        $shippingStep = is_array($first['shippingStep'] ?? null) ? $first['shippingStep'] : [];

        return is_array($shippingStep['shipTo'] ?? null) ? $shippingStep['shipTo'] : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapItem(array $item): NormalizedOrderItem
    {
        $cost = is_array($item['lineItemCost'] ?? null) ? $item['lineItemCost'] : [];
        $sku = ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null;

        return new NormalizedOrderItem(
            externalId: isset($item['lineItemId']) ? (string) $item['lineItemId'] : null,
            sku: $sku,
            title: (string) ($item['title'] ?? 'Item'),
            imageUrl: null,
            qty: max((int) ($item['quantity'] ?? 1), 1),
            price: (float) ($cost['value'] ?? 0), // already per-unit
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(array $raw): array
    {
        $fulfillment = (string) ($raw['orderFulfillmentStatus'] ?? 'NOT_STARTED');
        $payment = (string) ($raw['orderPaymentStatus'] ?? 'PENDING');
        $cancelState = is_array($raw['cancelStatus'] ?? null) ? (string) ($raw['cancelStatus']['cancelState'] ?? 'NONE_REQUESTED') : 'NONE_REQUESTED';

        if ($cancelState === 'CANCELED') {
            return [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, $this->mapPaymentStatus($payment)];
        }

        if ($payment === 'FULLY_REFUNDED') {
            return [Order::STATUS_REFUNDED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_REFUNDED];
        }

        if ($payment === 'PARTIALLY_REFUNDED') {
            return [Order::STATUS_REFUNDED, $this->mapFulfillmentStatus($fulfillment), Order::PAYMENT_PARTIALLY_REFUNDED];
        }

        if ($fulfillment === 'FULFILLED') {
            return [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, $this->mapPaymentStatus($payment)];
        }

        return [Order::STATUS_UNFULFILLED, $this->mapFulfillmentStatus($fulfillment), $this->mapPaymentStatus($payment)];
    }

    private function mapFulfillmentStatus(string $fulfillment): string
    {
        return match ($fulfillment) {
            'FULFILLED' => Order::FULFILLMENT_FULFILLED,
            'IN_PROGRESS' => Order::FULFILLMENT_PARTIAL,
            default => Order::FULFILLMENT_UNFULFILLED,
        };
    }

    private function mapPaymentStatus(string $payment): string
    {
        return match ($payment) {
            'PAID' => Order::PAYMENT_PAID,
            'FULLY_REFUNDED' => Order::PAYMENT_REFUNDED,
            'PARTIALLY_REFUNDED' => Order::PAYMENT_PARTIALLY_REFUNDED,
            'FAILED' => Order::PAYMENT_FAILED,
            default => Order::PAYMENT_PENDING,
        };
    }
}
