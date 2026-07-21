<?php

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * Thrown when the currently active AI provider (Plan §8.7.9) fails to
 * respond — a bad/expired key, an outage, or a malformed response.
 * `AskAssistantAction` catches this and returns a real, honest failure to
 * the user rather than a wrong or fabricated answer; it never debits the
 * team's question quota on a failed call.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
