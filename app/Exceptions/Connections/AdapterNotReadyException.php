<?php

namespace App\Exceptions\Connections;

use RuntimeException;

/**
 * Thrown when a platform's adapter can't yet perform an action because its
 * developer-account approval or OAuth app is still pending (Plan §15.2:
 * Amazon SP-API vetting, Etsy commercial approval, etc. take weeks).
 */
class AdapterNotReadyException extends RuntimeException
{
    public static function forPlatform(string $platform): self
    {
        return new self("The {$platform} integration isn't available yet — its developer account/app approval is still pending.");
    }

    public static function forFeature(string $platform, string $reason): self
    {
        return new self("The {$platform} integration doesn't support this yet — {$reason}.");
    }

    /**
     * Narrower than `forPlatform()`: the platform connection itself works
     * fine, but one specific capability sits behind its own separate
     * approval the merchant's connection hasn't been granted yet (Etsy's
     * conversations/messaging API on top of ordinary commercial access,
     * Plan §7.4/§7.8).
     */
    public static function forCapability(string $platform, string $capability): self
    {
        return new self("The {$platform} integration's {$capability} isn't available yet — it requires additional platform approval that hasn't been granted for this connection.");
    }
}
