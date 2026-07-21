<?php

namespace App\Support\Orders;

use Carbon\CarbonInterface;

/**
 * The adapter-agnostic order shape every ChannelAdapter's (future)
 * fetchOrders()/parseWebhook() will produce (Plan §8.2's "Order normalizer").
 */
final readonly class NormalizedOrder
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<string, mixed>  $raw
     * @param  array<int, NormalizedOrderItem>  $items
     */
    public function __construct(
        public string $externalId,
        public string $orderNumber,
        public string $status,
        public string $fulfillmentStatus,
        public string $paymentStatus,
        public string $currency,
        public float $total,
        public ?string $customerName,
        public ?string $customerEmail,
        public array $shippingAddress,
        public CarbonInterface $placedAt,
        public ?CarbonInterface $shipByAt,
        public array $tags,
        public array $raw,
        public bool $isTest,
        public array $items,
        /**
         * eBay's login handle for the buyer (Plan §7.3) — distinct from
         * `customerName` (a real name, when the platform exposes one) and
         * needed as the recipient identity for Trading API member messages
         * (`EbayAdapter::sendMessage()`). Always null for every other
         * platform.
         */
        public ?string $buyerUsername = null,
        /**
         * Best-effort, per-platform (Plan §4.12 Phase B) — populated only
         * where the source API actually exposes it (WooCommerce's
         * `discount_total`/`total_tax` today). Left `null` rather than
         * fabricated for every platform that doesn't expose it.
         */
        public ?float $discountAmount = null,
        public ?float $tax = null,
    ) {}
}
