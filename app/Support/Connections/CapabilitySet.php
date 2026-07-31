<?php

namespace App\Support\Connections;

/**
 * A platform's capability profile (Plan §7.8 matrix) — drives which action
 * buttons the mobile app renders for a given connection (§8.3).
 */
final readonly class CapabilitySet
{
    public function __construct(
        public bool $realtimeOrders,
        public bool $fulfillTracking,
        public bool $refunds,
        public bool $cancel,
        public string $messagingMode,
        public bool $inventoryUpdate,
        public bool $reviewsFeedback,
        public bool $payoutsAvailable = false,
        public bool $reviewReply = false,
        // Whether tagging an order in StockBeat also pushes that tag to
        // the platform's own order (merge, not overwrite — see
        // ShopifyAdapter::updateOrderTags()). False everywhere except
        // Shopify for now; no other adapter has this wired up yet.
        public bool $tagSync = false,
    ) {}

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'realtime_orders' => $this->realtimeOrders,
            'fulfill_tracking' => $this->fulfillTracking,
            'refunds' => $this->refunds,
            'cancel' => $this->cancel,
            'messaging_mode' => $this->messagingMode,
            'inventory_update' => $this->inventoryUpdate,
            'reviews_feedback' => $this->reviewsFeedback,
            'payouts_available' => $this->payoutsAvailable,
            'review_reply' => $this->reviewReply,
            'tag_sync' => $this->tagSync,
        ];
    }
}
