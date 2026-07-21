<?php

namespace App\Support\Ai\Providers;

/**
 * Groq's OpenAI-compatible endpoint — same request/response shape as
 * OpenAI's Chat Completions API, different host and model catalogue
 * (fast inference over open models, e.g. `llama-3.3-70b-versatile`).
 */
class GroqProvider extends OpenAiCompatibleProvider
{
    protected function baseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }
}
