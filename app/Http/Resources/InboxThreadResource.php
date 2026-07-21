<?php

namespace App\Http\Resources;

use App\Models\InboxThread;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InboxThread
 */
class InboxThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'connection_id' => $this->connection_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'assigned_to' => $this->assigned_to,
            'last_message_at' => $this->last_message_at,
        ];
    }
}
