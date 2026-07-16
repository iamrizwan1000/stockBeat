<?php

namespace App\Jobs\Concerns;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Per-store throttling (Plan §15.1): a job touching one store connection
 * (poller, webhook ingest) never runs concurrently with another job for
 * that same connection — a webhook-triggered ingest and the reconciliation
 * poller racing on the same store is exactly the kind of double-processing
 * this exists to prevent. Different connections are unaffected by each
 * other regardless of how many run at once.
 */
trait ThrottlesPerStoreConnection
{
    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('store-connection-'.$this->connectionId))->expireAfter(120),
        ];
    }
}
