<?php

namespace App\Http\Resources;

use App\Models\StoreConnection;
use App\Support\Connections\ChannelAdapterManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreConnection
 */
class StoreConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'name' => $this->name,
            'status' => $this->status,
            'last_sync_at' => $this->last_sync_at,
            'webhook_status' => $this->webhook_status,
            // Plan §8.3: drives which quick-action buttons the client
            // renders for this connection's orders — never hardcode the
            // §7.8 matrix client-side, read it from here instead.
            'capabilities' => app(ChannelAdapterManager::class)->driver($this->platform)->capabilities()->toArray(),
        ];
    }
}
