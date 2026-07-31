<?php

namespace App\Http\Resources;

use App\Actions\Connections\GetConnectionHealthAction;
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
        // Added 2026-07-31: the list previously carried only the raw
        // `status` code (e.g. "needs_reauth"), pushing every client to
        // invent its own copy for it — reuse the same plain-language
        // message the dedicated health endpoint returns instead, so the
        // Connections list itself can say *why* a store needs attention,
        // not just that it does.
        [$message, $fixAction] = app(GetConnectionHealthAction::class)->resolveMessage($this->resource);

        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'name' => $this->name,
            'status' => $this->status,
            'message' => $message,
            'fix_action' => $fixAction,
            'notifications_muted' => $this->notifications_muted,
            'last_sync_at' => $this->last_sync_at,
            'webhook_status' => $this->webhook_status,
            // Plan §8.3: drives which quick-action buttons the client
            // renders for this connection's orders — never hardcode the
            // §7.8 matrix client-side, read it from here instead.
            'capabilities' => app(ChannelAdapterManager::class)->driver($this->platform)->capabilities()->toArray(),
        ];
    }
}
