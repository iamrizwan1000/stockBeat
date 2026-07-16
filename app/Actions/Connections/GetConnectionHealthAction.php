<?php

namespace App\Actions\Connections;

use App\Models\StoreConnection;

/**
 * Connection health screen (Plan §4.1.1): "plain-language error states with
 * a Fix it button — never raw error codes." `fix_action` is a key the
 * mobile app maps to a concrete flow (e.g. re-auth), not a URL — we don't
 * have a reconnect flow built yet, so it's advisory metadata for now.
 */
class GetConnectionHealthAction
{
    private const STALE_SYNC_HOURS = 2;

    /**
     * @return array<string, mixed>
     */
    public function handle(StoreConnection $connection): array
    {
        [$message, $fixAction] = match (true) {
            $connection->status === StoreConnection::STATUS_NEEDS_REAUTH => [
                "Your connection to {$connection->name} needs to be reconnected.",
                'reauth',
            ],
            $connection->status === StoreConnection::STATUS_DISCONNECTED => [
                "This store isn't connected.",
                'reconnect',
            ],
            $connection->last_sync_at === null => [
                "We haven't synced {$connection->name} yet — this can take a minute after connecting.",
                null,
            ],
            $connection->last_sync_at->lt(now()->subHours(self::STALE_SYNC_HOURS)) => [
                "{$connection->name} hasn't synced in a while — we'll keep retrying automatically.",
                'check_connection',
            ],
            $connection->webhook_status === 'partial' => [
                "{$connection->name} is syncing normally, though some webhook alerts didn't register — periodic syncing covers the gap.",
                null,
            ],
            default => ["{$connection->name} is connected and syncing normally.", null],
        };

        return [
            'connection_id' => $connection->id,
            'status' => $connection->status,
            'webhook_status' => $connection->webhook_status,
            'last_sync_at' => $connection->last_sync_at?->toIso8601String(),
            'message' => $message,
            'fix_action' => $fixAction,
        ];
    }
}
