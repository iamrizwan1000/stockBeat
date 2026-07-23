<?php

namespace App\Actions\Connections;

use App\Models\StoreConnection;

/**
 * Toggles `notifications_muted` on a connection (Plan §4.8 follow-up —
 * per-store mute without disconnecting). Deliberately the only field this
 * Action touches; it is not a general connection-edit path.
 */
class UpdateConnectionNotificationMuteAction
{
    public function handle(StoreConnection $connection, bool $muted): StoreConnection
    {
        $connection->update(['notifications_muted' => $muted]);

        return $connection;
    }
}
