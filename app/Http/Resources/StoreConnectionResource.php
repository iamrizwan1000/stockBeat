<?php

namespace App\Http\Resources;

use App\Models\StoreConnection;
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
        ];
    }
}
