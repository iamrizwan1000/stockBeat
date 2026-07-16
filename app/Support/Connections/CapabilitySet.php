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
        ];
    }
}
