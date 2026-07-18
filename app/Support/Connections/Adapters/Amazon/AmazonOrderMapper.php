<?php

namespace App\Support\Connections\Adapters\Amazon;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw SP-API Orders API order (Plan §7.5) into our platform-agnostic
 * NormalizedOrder. Unlike every other platform's order-list endpoint,
 * SP-API's `getOrders` response carries order headers only — line items
 * come from a *separate* `getOrderItems` call per order (Plan §7.5's own
 * "getOrders/getOrderItems" wording) — so `map()` takes both responses
 * rather than one combined payload; `AmazonAdapter::fetchOrders()` is
 * responsible for making both calls per order before handing them here.
 *
 * Buyer PII (`BuyerInfo`/`ShippingAddress.Name` etc.) is only present in
 * the raw payload when the caller obtained a Restricted Data Token for
 * this order (Plan §7.5: "PII requires Restricted Data Token via RDT") —
 * this mapper simply maps whatever fields are present and leaves the rest
 * null, same as EbayOrderMapper always leaving `customerEmail` null for a
 * genuine platform limitation rather than a mapping bug.
 */
class AmazonOrderMapper
{
    /**
     * @param  array<string, mixed>  $order  Raw `Orders[]` entry from getOrders.
     * @param  array<int, array<string, mixed>>  $items  Raw `OrderItems` array from getOrderItems.
     */
    public function map(array $order, array $items = []): NormalizedOrder
    {
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus((string) ($order['OrderStatus'] ?? 'Pending'));

        $total = is_array($order['OrderTotal'] ?? null) ? $order['OrderTotal'] : [];
        $buyerInfo = is_array($order['BuyerInfo'] ?? null) ? $order['BuyerInfo'] : [];
        $shippingAddress = is_array($order['ShippingAddress'] ?? null) ? $order['ShippingAddress'] : [];

        $customerName = $buyerInfo['BuyerName'] ?? $shippingAddress['Name'] ?? null;
        $customerEmail = $buyerInfo['BuyerEmail'] ?? null;

        return new NormalizedOrder(
            externalId: (string) ($order['AmazonOrderId'] ?? ''),
            orderNumber: '#'.($order['AmazonOrderId'] ?? ''),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($total['CurrencyCode'] ?? 'USD'),
            total: (float) ($total['Amount'] ?? 0),
            customerName: is_string($customerName) && $customerName !== '' ? $customerName : null,
            customerEmail: is_string($customerEmail) && $customerEmail !== '' ? $customerEmail : null,
            shippingAddress: [
                'line1' => $shippingAddress['AddressLine1'] ?? null,
                'line2' => $shippingAddress['AddressLine2'] ?? null,
                'city' => $shippingAddress['City'] ?? null,
                'state' => $shippingAddress['StateOrRegion'] ?? null,
                'postcode' => $shippingAddress['PostalCode'] ?? null,
                'country' => $shippingAddress['CountryCode'] ?? null,
            ],
            placedAt: isset($order['PurchaseDate']) ? Carbon::parse($order['PurchaseDate'])->utc() : Carbon::now(),
            // LatestShipDate is Amazon's own handling-time SLA deadline
            // (Plan's "ship-by" concept, same idea as eBay's handling-time
            // SLA) — present whenever Amazon has computed one for the order.
            shipByAt: isset($order['LatestShipDate']) ? Carbon::parse($order['LatestShipDate'])->utc() : null,
            tags: [],
            raw: ['order' => $order, 'items' => $items],
            // SP-API's Sandbox is a wholly separate environment (like
            // eBay's), not a per-order test flag the way Shopify has —
            // always false for real order data.
            isTest: false,
            items: collect($items)
                ->map(fn (array $item) => $this->mapItem($item))
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapItem(array $item): NormalizedOrderItem
    {
        $qty = max((int) ($item['QuantityOrdered'] ?? 1), 1);
        $price = is_array($item['ItemPrice'] ?? null) ? $item['ItemPrice'] : [];

        // ItemPrice is the *line* total for the whole QuantityOrdered, not a
        // per-unit price — a real SP-API quirk, same total/qty division
        // WooOrderMapper already does for Woo's identical line-total shape.
        $lineTotal = (float) ($price['Amount'] ?? 0);
        $sku = ($item['SellerSKU'] ?? '') !== '' ? (string) $item['SellerSKU'] : null;

        return new NormalizedOrderItem(
            externalId: isset($item['OrderItemId']) ? (string) $item['OrderItemId'] : null,
            sku: $sku,
            title: (string) ($item['Title'] ?? 'Item'),
            imageUrl: null,
            qty: $qty,
            price: round($lineTotal / $qty, 2),
        );
    }

    /**
     * Amazon's real order-status enum (Plan §7.5): Pending, Unshipped,
     * PartiallyShipped, Shipped, Canceled, Unfulfillable — plus a few rarer
     * values (InvoiceUnconfirmed, PendingAvailability) Amazon documents but
     * this table treats as "still new", mirroring WooOrderMapper's own
     * "anything unrecognized falls back to new/pending" default.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(string $amazonStatus): array
    {
        return match ($amazonStatus) {
            'Pending' => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            'Unshipped' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
            'PartiallyShipped' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_PARTIAL, Order::PAYMENT_PAID],
            'Shipped' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'Canceled' => [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            // Amazon (typically FBA) decided the order can't be fulfilled at
            // all — closer to a cancellation than a failed payment from the
            // merchant's point of view, so this maps the same as Canceled
            // rather than inventing a status this app doesn't otherwise have.
            'Unfulfillable' => [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            default => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
        };
    }
}
