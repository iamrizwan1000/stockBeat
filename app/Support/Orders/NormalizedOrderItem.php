<?php

namespace App\Support\Orders;

final readonly class NormalizedOrderItem
{
    public function __construct(
        public ?string $externalId,
        public ?string $sku,
        public string $title,
        public ?string $imageUrl,
        public int $qty,
        public float $price,
        /**
         * eBay's legacy `ItemID` (Plan §7.3 gotcha: "mixed REST + legacy
         * XML... isolate legacy calls inside the adapter") — the Sell
         * Fulfillment API's REST line items carry it as a transitional
         * field bridging to the old Trading API's id space, which
         * `EbayAdapter::sendMessage()` needs. Always null for every other
         * platform.
         */
        public ?string $legacyItemId = null,
    ) {}
}
