<?php

namespace App\Support\Connections\Adapters\Woo;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw WooCommerce REST API v3 order object (identical shape whether
 * it arrives via webhook payload or a polled list response) into our
 * platform-agnostic NormalizedOrder (Plan §7.2).
 */
class WooOrderMapper
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function map(array $raw): NormalizedOrder
    {
        $wooStatus = (string) ($raw['status'] ?? 'pending');
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus($wooStatus);

        $billing = is_array($raw['billing'] ?? null) ? $raw['billing'] : [];
        $shipping = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];

        $customerName = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        $placedAt = $raw['date_created_gmt'] ?? $raw['date_created'] ?? null;

        /** @var array<int, array<string, mixed>> $lineItems */
        $lineItems = is_array($raw['line_items'] ?? null) ? $raw['line_items'] : [];

        return new NormalizedOrder(
            externalId: (string) $raw['id'],
            orderNumber: '#'.($raw['number'] ?? $raw['id']),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($raw['currency'] ?? 'USD'),
            total: (float) ($raw['total'] ?? 0),
            discountAmount: isset($raw['discount_total']) ? (float) $raw['discount_total'] : null,
            tax: isset($raw['total_tax']) ? (float) $raw['total_tax'] : null,
            customerName: $customerName !== '' ? $customerName : null,
            customerEmail: $billing['email'] ?? null,
            shippingAddress: [
                'line1' => $shipping['address_1'] ?? null,
                'line2' => $shipping['address_2'] ?? null,
                'city' => $shipping['city'] ?? null,
                'state' => $shipping['state'] ?? null,
                'postcode' => $shipping['postcode'] ?? null,
                'country' => $shipping['country'] ?? null,
            ],
            placedAt: $placedAt !== null ? Carbon::parse($placedAt)->utc() : Carbon::now(),
            shipByAt: null, // Woo has no marketplace handling-time SLA (§7.2)
            tags: [],
            raw: $raw,
            isTest: false, // Woo has no "test order" concept like Shopify (§7.2)
            items: collect($lineItems)
                ->map(fn (array $item) => $this->mapItem($item))
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapItem(array $item): NormalizedOrderItem
    {
        $qty = max((int) ($item['quantity'] ?? 1), 1);
        $lineTotal = (float) ($item['total'] ?? 0);
        $sku = ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null;
        $image = is_array($item['image'] ?? null) ? $item['image'] : [];

        return new NormalizedOrderItem(
            externalId: isset($item['id']) ? (string) $item['id'] : null,
            sku: $sku,
            title: (string) ($item['name'] ?? 'Item'),
            imageUrl: $image['src'] ?? null,
            qty: $qty,
            price: round($lineTotal / $qty, 2),
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(string $wooStatus): array
    {
        return match ($wooStatus) {
            'processing' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
            'completed' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'cancelled', 'trash' => [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            'refunded' => [Order::STATUS_REFUNDED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_REFUNDED],
            'failed' => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_FAILED],
            default => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
        };
    }
}
