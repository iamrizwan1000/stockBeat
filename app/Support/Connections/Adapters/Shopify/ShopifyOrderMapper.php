<?php

namespace App\Support\Connections\Adapters\Shopify;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw Shopify REST Admin API order object (identical shape whether
 * it arrives via webhook payload or a polled list response) into our
 * platform-agnostic NormalizedOrder (Plan §7.1). Unlike WooCommerce,
 * Shopify separates fulfillment and payment into two independent fields
 * (`fulfillment_status`, `financial_status`) rather than one unified
 * status string, and `cancelled_at` is its own signal on top of both.
 */
class ShopifyOrderMapper
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function map(array $raw): NormalizedOrder
    {
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus($raw);

        $customer = is_array($raw['customer'] ?? null) ? $raw['customer'] : [];
        $shipping = is_array($raw['shipping_address'] ?? null) ? $raw['shipping_address'] : [];

        $customerName = trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? ''));
        $tagsString = (string) ($raw['tags'] ?? '');

        /** @var array<int, array<string, mixed>> $lineItems */
        $lineItems = is_array($raw['line_items'] ?? null) ? $raw['line_items'] : [];

        return new NormalizedOrder(
            externalId: (string) $raw['id'],
            orderNumber: (string) ($raw['name'] ?? '#'.$raw['id']),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($raw['currency'] ?? 'USD'),
            total: (float) ($raw['total_price'] ?? 0),
            customerName: $customerName !== '' ? $customerName : null,
            customerEmail: $raw['email'] ?? $customer['email'] ?? null,
            shippingAddress: [
                'line1' => $shipping['address1'] ?? null,
                'line2' => $shipping['address2'] ?? null,
                'city' => $shipping['city'] ?? null,
                'state' => $shipping['province'] ?? null,
                'postcode' => $shipping['zip'] ?? null,
                'country' => $shipping['country_code'] ?? null,
            ],
            placedAt: isset($raw['created_at']) ? Carbon::parse($raw['created_at'])->utc() : Carbon::now(),
            shipByAt: null, // Shopify has no marketplace handling-time SLA, unlike eBay/Etsy (§7.1)
            tags: $tagsString !== '' ? array_map('trim', explode(',', $tagsString)) : [],
            raw: $raw,
            isTest: (bool) ($raw['test'] ?? false),
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
        $sku = ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null;

        return new NormalizedOrderItem(
            externalId: isset($item['id']) ? (string) $item['id'] : null,
            sku: $sku,
            title: (string) ($item['title'] ?? 'Item'),
            imageUrl: null, // REST line items don't include images; would need a separate product lookup
            qty: max((int) ($item['quantity'] ?? 1), 1),
            price: (float) ($item['price'] ?? 0), // already per-unit, unlike Woo's line total
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(array $raw): array
    {
        $financial = (string) ($raw['financial_status'] ?? 'pending');
        $fulfillment = $raw['fulfillment_status'] ?? null;
        $cancelled = ($raw['cancelled_at'] ?? null) !== null;

        if ($cancelled) {
            return [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, $this->mapPaymentStatus($financial)];
        }

        if ($financial === 'refunded') {
            return [Order::STATUS_REFUNDED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_REFUNDED];
        }

        if ($financial === 'partially_refunded') {
            return [Order::STATUS_REFUNDED, $this->mapFulfillmentStatus($fulfillment), Order::PAYMENT_PARTIALLY_REFUNDED];
        }

        if ($fulfillment === 'fulfilled') {
            return [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, $this->mapPaymentStatus($financial)];
        }

        return [Order::STATUS_UNFULFILLED, $this->mapFulfillmentStatus($fulfillment), $this->mapPaymentStatus($financial)];
    }

    private function mapFulfillmentStatus(mixed $fulfillment): string
    {
        return match ($fulfillment) {
            'fulfilled' => Order::FULFILLMENT_FULFILLED,
            'partial' => Order::FULFILLMENT_PARTIAL,
            default => Order::FULFILLMENT_UNFULFILLED,
        };
    }

    private function mapPaymentStatus(string $financial): string
    {
        return match ($financial) {
            'paid' => Order::PAYMENT_PAID,
            'partially_paid' => Order::PAYMENT_PAID,
            'refunded' => Order::PAYMENT_REFUNDED,
            'partially_refunded' => Order::PAYMENT_PARTIALLY_REFUNDED,
            'voided' => Order::PAYMENT_FAILED,
            default => Order::PAYMENT_PENDING,
        };
    }
}
