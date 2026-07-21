<?php

namespace App\Contracts;

use App\Support\Ai\AiChatResult;

/**
 * Every AI provider (OpenAI/Groq/Claude, Plan §4.12/§8.7.9) implements this
 * — the assistant's tool-calling loop (`AskAssistantAction`) talks to
 * whichever provider is active without knowing which one it is.
 *
 * Messages use one normalized internal shape regardless of provider:
 *   ['role' => 'system'|'user', 'content' => string]
 *   ['role' => 'assistant', 'content' => ?string, 'tool_calls' => ?array]
 *   ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string]
 * Each driver translates this into its own wire format (OpenAI/Groq share
 * one shape natively; Claude's Messages API needs real translation — see
 * `ClaudeProvider`).
 */
interface AiProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools  JSON-schema tool definitions: [name, description, parameters]
     */
    public function chat(array $messages, array $tools): AiChatResult;
}
