<?php

namespace App\Support\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiProviderException;
use App\Models\AiProviderSetting;
use App\Support\Ai\AiChatResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic's Messages API (Plan §4.12/§8.7.9) — genuinely different wire
 * shape from OpenAI/Groq, not just a different host: the system prompt is
 * a top-level field rather than a message, tool definitions use
 * `input_schema` instead of `parameters`, and tool round-trips are
 * represented as content blocks (`tool_use`/`tool_result`) inside
 * assistant/user messages rather than a dedicated `tool` role. This driver
 * is what actually translates the app's one normalized message shape into
 * that format.
 */
class ClaudeProvider implements AiProvider
{
    private const API_VERSION = '2023-06-01';

    public function __construct(private readonly AiProviderSetting $setting) {}

    public function chat(array $messages, array $tools): AiChatResult
    {
        $system = null;
        $conversation = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $message['content'];

                continue;
            }

            $conversation[] = $this->toWireMessage($message);
        }

        $payload = [
            'model' => $this->setting->model,
            'max_tokens' => 1024,
            'messages' => $conversation,
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $tools);
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) $this->setting->api_key,
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', $payload)
                ->throw();
        } catch (RequestException $e) {
            throw new AiProviderException("AI provider request failed: {$e->getMessage()}", previous: $e);
        }

        $blocks = (array) $response->json('content', []);

        $text = collect($blocks)->firstWhere('type', 'text')['text'] ?? null;

        $toolCalls = collect($blocks)
            ->where('type', 'tool_use')
            ->map(fn (array $block): array => [
                'id' => (string) $block['id'],
                'name' => (string) $block['name'],
                'arguments' => $block['input'] ?? [],
            ])
            ->values()
            ->all();

        return new AiChatResult($text, $toolCalls);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function toWireMessage(array $message): array
    {
        if ($message['role'] === 'tool') {
            return [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'],
                    'content' => $message['content'],
                ]],
            ];
        }

        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            $blocks = [];

            if (! empty($message['content'])) {
                $blocks[] = ['type' => 'text', 'text' => $message['content']];
            }

            foreach ($message['tool_calls'] as $call) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'input' => $call['arguments'],
                ];
            }

            return ['role' => 'assistant', 'content' => $blocks];
        }

        return ['role' => $message['role'], 'content' => $message['content']];
    }
}
