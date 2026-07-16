<?php

namespace App\Actions\Inbox;

/**
 * Extracts the plus-addressed routing token from an inbound email's `to`
 * address (Plan §4.5/§4.9/§7.7: "inbound email threads back into the same
 * conversation via plus-addressing, same mechanism" for both the unified
 * customer inbox and support chat). `support+42@mail.stockbeat.app` routes
 * to support thread 42; `thread+17@mail.stockbeat.app` routes to unified
 * inbox thread 17. Malformed or unrecognized addresses return `null` —
 * callers must treat that as "drop it," not "guess."
 */
class ParseInboundEmailTokenAction
{
    private const PREFIXES = ['support', 'thread'];

    /**
     * @return array{prefix: string, id: int}|null
     */
    public function handle(string $toAddress): ?array
    {
        $localPart = explode('@', $toAddress, 2)[0];

        if (! str_contains($localPart, '+')) {
            return null;
        }

        [$prefix, $id] = explode('+', $localPart, 2);

        if (! in_array($prefix, self::PREFIXES, true) || ! ctype_digit($id)) {
            return null;
        }

        return ['prefix' => $prefix, 'id' => (int) $id];
    }
}
