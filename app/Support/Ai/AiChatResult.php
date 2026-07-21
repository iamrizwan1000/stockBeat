<?php

namespace App\Support\Ai;

/**
 * A provider's normalized reply to one `chat()` turn (Plan §4.12): either a
 * final text answer, or one or more tool calls the assistant wants
 * executed before it can answer — never both being meaningfully absent,
 * since a provider that returns neither is a real error, not a valid state.
 */
class AiChatResult
{
    /**
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
