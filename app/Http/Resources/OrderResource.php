<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'connection_id' => $this->connection_id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'fulfillment_status' => $this->fulfillment_status,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'total' => $this->total,
            'discount_amount' => $this->discount_amount,
            'tax' => $this->tax,
            'total_base_currency' => $this->total_base_currency,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'shipping_address' => $this->shipping_address,
            'placed_at' => $this->placed_at,
            'ship_by_at' => $this->ship_by_at,
            'ship_by_hours_remaining' => $this->ship_by_at === null ? null : round(now()->diffInHours($this->ship_by_at, false), 1),
            'is_ship_by_urgent' => $this->ship_by_at !== null && now()->diffInHours($this->ship_by_at, false) <= 24,
            'tags' => $this->tags,
            'is_test' => $this->is_test,
            'snoozed_until' => $this->snoozed_until,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'notes' => OrderNoteResource::collection($this->whenLoaded('notes')),
        ];
    }
}
