<?php

namespace App\Support\Connections\Adapters\TikTok;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw TikTok Shop Order Detail object (Partner API v2's
 * `/order/202309/orders` response, Plan §7.6) into our platform-agnostic
 * NormalizedOrder. Our internal `Order` model only has five terminal
 * statuses (new/unfulfilled/shipped/refunded/cancelled) — narrower than
 * TikTok's own richer post-ship lifecycle (`IN_TRANSIT`/`DELIVERED`/
 * `COMPLETED` are all distinct states on TikTok's side), so all three
 * collapse onto `STATUS_SHIPPED` the same way Shopify's own "fulfilled"
 * doesn't distinguish in-transit from delivered.
 *
 * TikTok Shop masks buyer PII more aggressively than Shopify/Woo — there is
 * no direct, unmasked buyer email on this resource (same real platform
 * limitation already documented on `EbayOrderMapper`), so `customerEmail`
 * is always null here.
 */
class TikTokOrderMapper
{
    /**
     * @param  array<string, mixed>  $raw  A single order object from the Order Detail/Search response.
     */
    public function map(array $raw): NormalizedOrder
    {
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus((string) ($raw['order_status'] ?? 'UNPAID'));

        $payment = is_array($raw['payment'] ?? null) ? $raw['payment'] : [];
        $recipient = is_array($raw['recipient_address'] ?? null) ? $raw['recipient_address'] : [];

        // Verify at build time: the exact recipient_address field names
        // (`address_detail` vs. a fuller `address_line1`/`address_line2`
        // pair) differ slightly across Partner API versions/regions — this
        // maps the fields documented as of this writing.
        $customerName = $recipient['name'] ?? null;

        /** @var array<int, array<string, mixed>> $lineItems */
        $lineItems = is_array($raw['line_items'] ?? null) ? $raw['line_items'] : [];

        return new NormalizedOrder(
            externalId: (string) ($raw['id'] ?? ''),
            orderNumber: '#'.($raw['id'] ?? ''),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($payment['currency'] ?? 'USD'),
            total: (float) ($payment['total_amount'] ?? 0),
            customerName: is_string($customerName) && $customerName !== '' ? $customerName : null,
            // TikTok Shop's Order API doesn't expose a buyer email on this
            // resource — a real platform limitation (Plan §7.8's own
            // messaging-mode note applies to buyer identity generally), not
            // a gap in this mapper.
            customerEmail: null,
            shippingAddress: [
                'line1' => $recipient['address_detail'] ?? $recipient['address_line1'] ?? null,
                'line2' => $recipient['address_line2'] ?? null,
                'city' => $recipient['city'] ?? null,
                'state' => $recipient['state'] ?? $recipient['region'] ?? null,
                'postcode' => $recipient['zipcode'] ?? $recipient['postal_code'] ?? null,
                'country' => $recipient['region_code'] ?? null,
            ],
            placedAt: isset($raw['create_time']) ? Carbon::createFromTimestamp((int) $raw['create_time'])->utc() : Carbon::now(),
            // TikTok's seller-facing shipping SLA deadline — verify the
            // exact field name at build time (`ttl_sla_time` and
            // `collection_due_time` are both used across different Partner
            // API surfaces/regions as of this writing); mapped here as
            // best-effort, matching this codebase's convention of not
            // fabricating a value when genuinely uncertain.
            shipByAt: isset($raw['ttl_sla_time']) ? Carbon::createFromTimestamp((int) $raw['ttl_sla_time'])->utc() : null,
            tags: [],
            raw: $raw,
            // TikTok Shop's sandbox is a separate Partner Center environment
            // rather than a per-order test flag — no equivalent signal on
            // this resource (same as Woo/eBay, §7.2/§7.3).
            isTest: false,
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
        $sku = ($item['seller_sku'] ?? '') !== '' ? (string) $item['seller_sku'] : null;

        return new NormalizedOrderItem(
            externalId: isset($item['id']) ? (string) $item['id'] : null,
            sku: $sku,
            title: (string) ($item['product_name'] ?? 'Item'),
            imageUrl: is_string($item['sku_image'] ?? null) && $item['sku_image'] !== '' ? $item['sku_image'] : null,
            qty: max((int) ($item['quantity'] ?? 1), 1),
            // `sale_price` is documented as the item's own unit price
            // (unlike Amazon/Woo's line-total-that-needs-dividing quirk) —
            // verify this against a real sandbox order at build time.
            price: (float) ($item['sale_price'] ?? 0),
        );
    }

    /**
     * TikTok Shop's real order-status enum (Plan §7.6). `ON_HOLD` and
     * `PARTIALLY_SHIPPING` are less consistently documented than the rest —
     * mapped by best-effort meaning (a paid order held by risk review, and
     * a multi-package order with some but not all packages shipped,
     * respectively) rather than guessed wildly; confirm both against a real
     * Partner Center sandbox order before relying on them in production.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(string $tiktokStatus): array
    {
        return match ($tiktokStatus) {
            'UNPAID' => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            'ON_HOLD' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
            'AWAITING_SHIPMENT' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID],
            'PARTIALLY_SHIPPING' => [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_PARTIAL, Order::PAYMENT_PAID],
            'AWAITING_COLLECTION' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'IN_TRANSIT' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'DELIVERED' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'COMPLETED' => [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, Order::PAYMENT_PAID],
            'CANCELLED' => [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
            default => [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING],
        };
    }
}
