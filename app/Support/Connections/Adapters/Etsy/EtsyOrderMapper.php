<?php

namespace App\Support\Connections\Adapters\Etsy;

use App\Models\Order;
use App\Support\Orders\NormalizedOrder;
use App\Support\Orders\NormalizedOrderItem;
use Illuminate\Support\Carbon;

/**
 * Maps a raw Etsy Open API v3 "Receipt" object into our platform-agnostic
 * NormalizedOrder (Plan §7.4). Etsy represents money as `{amount, divisor,
 * currency_code}` rather than a plain decimal — real value is
 * `amount / divisor` — and shipping address fields sit directly on the
 * receipt rather than nested (unlike Woo/Shopify/eBay).
 */
class EtsyOrderMapper
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function map(array $raw): NormalizedOrder
    {
        [$status, $fulfillmentStatus, $paymentStatus] = $this->mapStatus($raw);

        $total = is_array($raw['grandtotal'] ?? null) ? $raw['grandtotal'] : [];

        /** @var array<int, array<string, mixed>> $transactions */
        $transactions = is_array($raw['transactions'] ?? null) ? $raw['transactions'] : [];

        return new NormalizedOrder(
            externalId: (string) ($raw['receipt_id'] ?? ''),
            orderNumber: '#'.($raw['receipt_id'] ?? ''),
            status: $status,
            fulfillmentStatus: $fulfillmentStatus,
            paymentStatus: $paymentStatus,
            currency: (string) ($total['currency_code'] ?? 'USD'),
            total: $this->money($total),
            customerName: ($raw['name'] ?? '') !== '' ? (string) $raw['name'] : null,
            customerEmail: $raw['buyer_email'] ?? null,
            shippingAddress: [
                'line1' => $raw['first_line'] ?? null,
                'line2' => $raw['second_line'] ?? null,
                'city' => $raw['city'] ?? null,
                'state' => $raw['state'] ?? null,
                'postcode' => $raw['zip'] ?? null,
                'country' => $raw['country_iso'] ?? null,
            ],
            placedAt: isset($raw['created_timestamp']) ? Carbon::createFromTimestamp((int) $raw['created_timestamp'])->utc() : Carbon::now(),
            // Handling-time SLA lives on the listing's processing_min/max,
            // not the receipt itself — deliberate v1 scope cut, same as
            // Shopify/eBay's shipByAt.
            shipByAt: null,
            tags: [],
            raw: $raw,
            // Etsy sandbox/production aren't separate environments the way
            // eBay's are, and receipts have no test flag — always false.
            isTest: false,
            items: collect($transactions)
                ->map(fn (array $item) => $this->mapItem($item))
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapItem(array $item): NormalizedOrderItem
    {
        $price = is_array($item['price'] ?? null) ? $item['price'] : [];
        $sku = ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null;

        return new NormalizedOrderItem(
            externalId: isset($item['transaction_id']) ? (string) $item['transaction_id'] : null,
            sku: $sku,
            title: (string) ($item['title'] ?? 'Item'),
            imageUrl: null,
            qty: max((int) ($item['quantity'] ?? 1), 1),
            price: $this->money($price), // already per-unit
        );
    }

    /**
     * @param  array<string, mixed>  $money
     */
    private function money(array $money): float
    {
        $amount = (float) ($money['amount'] ?? 0);
        $divisor = (float) ($money['divisor'] ?? 100);

        return $divisor > 0 ? round($amount / $divisor, 2) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: string, 1: string, 2: string}
     */
    private function mapStatus(array $raw): array
    {
        $status = (string) ($raw['status'] ?? 'open');
        $wasShipped = (bool) ($raw['was_shipped'] ?? false);
        $wasPaid = (bool) ($raw['was_paid'] ?? false);

        if ($status === 'canceled') {
            return [Order::STATUS_CANCELLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING];
        }

        if ($wasShipped) {
            return [Order::STATUS_SHIPPED, Order::FULFILLMENT_FULFILLED, $wasPaid ? Order::PAYMENT_PAID : Order::PAYMENT_PENDING];
        }

        if ($wasPaid) {
            return [Order::STATUS_UNFULFILLED, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PAID];
        }

        return [Order::STATUS_NEW, Order::FULFILLMENT_UNFULFILLED, Order::PAYMENT_PENDING];
    }
}
