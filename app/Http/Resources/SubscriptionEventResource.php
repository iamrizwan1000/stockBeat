<?php

namespace App\Http\Resources;

use App\Models\SubscriptionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubscriptionEvent
 */
class SubscriptionEventResource extends JsonResource
{
    /**
     * RevenueCat's raw event vocabulary mapped to seller-facing copy. Both the
     * raw `event_type` and this label are exposed: clients branch on the raw
     * value (it's stable), and render `description` (it isn't SHOUTY_SNAKE_CASE).
     * An unrecognised type falls back to a humanised form of the raw value
     * rather than an empty string or a guess — RevenueCat can add event types
     * without an app release.
     */
    private const DESCRIPTIONS = [
        'INITIAL_PURCHASE' => 'Subscription started',
        'RENEWAL' => 'Renewed',
        'PRODUCT_CHANGE' => 'Plan changed',
        'CANCELLATION' => 'Auto-renew cancelled',
        'UNCANCELLATION' => 'Auto-renew resumed',
        'EXPIRATION' => 'Subscription expired',
        'BILLING_ISSUE' => 'Payment issue',
        'NON_RENEWING_PURCHASE' => 'Credit top-up',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'description' => self::DESCRIPTIONS[$this->event_type] ?? $this->humanise($this->event_type),
            'product_id' => $this->productId(),
            // Stays null when RevenueCat's payload carried no price (see
            // `ProcessRevenueCatEventAction::extractPrice()`) — never coerced
            // to 0, which would read as a free transaction rather than an
            // unknown one.
            'price' => $this->price === null ? null : (float) $this->price,
            'currency' => $this->currency,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }

    private function productId(): ?string
    {
        $productId = $this->raw_payload['product_id'] ?? null;

        return is_string($productId) && $productId !== '' ? $productId : null;
    }

    private function humanise(string $eventType): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $eventType)));
    }
}
