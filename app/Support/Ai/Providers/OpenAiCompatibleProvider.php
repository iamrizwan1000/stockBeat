<?php

namespace App\Support\Ai\Providers;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiProviderException;
use App\Models\AiProviderSetting;
use App\Support\Ai\AiChatResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Shared implementation for OpenAI and Groq (Plan §4.12/§8.7.9) — Groq's
 * API is wire-compatible with OpenAI's Chat Completions + function-calling
 * format, so both drivers differ only in base URL. A plain `Http::` call,
 * not an SDK — same convention already used for Twilio/WooCommerce
 * (`SendSmsNotificationAction`).
 */
abstract class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(protected readonly AiProviderSetting $setting) {}

    abstract protected function baseUrl(): string;

    public function chat(array $messages, array $tools): AiChatResult
    {
        $payload = [
            'model' => $this->setting->model,
            'messages' => array_map($this->toWireMessage(...), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (array $tool): array => [
                'type' => 'function',
                'function' => $tool,
            ], $tools);
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken((string) $this->setting->api_key)
                ->timeout(30)
                ->post($this->baseUrl().'/chat/completions', $payload)
                ->throw();
        } catch (RequestException $e) {
            throw new AiProviderException("AI provider request failed: {$e->getMessage()}", previous: $e);
        }

        $message = (array) $response->json('choices.0.message', []);

        /** @var array<int, array<string, mixed>> $rawToolCalls */
        $rawToolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

        $toolCalls = collect($rawToolCalls)
            ->map(fn (array $call): array => [
                'id' => (string) $call['id'],
                'name' => (string) $call['function']['name'],
                'arguments' => json_decode((string) $call['function']['arguments'], true) ?? [],
            ])
            ->all();

        return new AiChatResult($message['content'] ?? null, $toolCalls);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function toWireMessage(array $message): array
    {
        if ($message['role'] === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => $message['tool_call_id'],
                'content' => $message['content'],
            ];
        }

        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            return [
                'role' => 'assistant',
                'content' => $message['content'],
                'tool_calls' => array_map(fn (array $call): array => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments']),
                    ],
                ], $message['tool_calls']),
            ];
        }

        return ['role' => $message['role'], 'content' => $message['content']];
    }
}
