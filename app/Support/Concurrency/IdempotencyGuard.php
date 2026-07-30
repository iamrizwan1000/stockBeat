<?php

namespace App\Support\Concurrency;

use Illuminate\Support\Facades\Cache;

/**
 * A shared "prevent a rapid repeat" primitive for mutating actions that
 * have no other natural guard against a double-tap/rapid-retry (a slow
 * 2G connection making a user think their first tap didn't register, or
 * two truly simultaneous requests racing each other). Backed by Redis
 * (`QUEUE_CONNECTION=redis`), so `Cache::lock()` is a genuine atomic
 * acquire, not a check-then-write race.
 */
class IdempotencyGuard
{
    /**
     * Runs `$callback` only if no other call for `$key` has run within the
     * last `$ttlSeconds` — otherwise returns null. The lock is deliberately
     * never released early: it staying held for the full TTL *is* the
     * "don't repeat this so soon" window, not just a mutex around the
     * callback's own execution time.
     */
    public static function once(string $key, int $ttlSeconds, callable $callback): mixed
    {
        if (! Cache::lock("idempotency:{$key}", $ttlSeconds)->get()) {
            return null;
        }

        return $callback();
    }
}
