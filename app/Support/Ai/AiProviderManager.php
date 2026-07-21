<?php

namespace App\Support\Ai;

use App\Contracts\AiProvider;
use App\Exceptions\Ai\AiProviderException;
use App\Models\AiProviderSetting;
use App\Support\Ai\Providers\ClaudeProvider;
use App\Support\Ai\Providers\GroqProvider;
use App\Support\Ai\Providers\OpenAiProvider;
use Illuminate\Support\Manager;

/**
 * Resolves the active AI provider (Plan §4.12/§8.7.9) — same Manager
 * pattern as `ChannelAdapterManager`, except the "driver name" comes from
 * `ai_provider_settings.active` in the database instead of a static config
 * value, so an admin switching providers takes effect on the very next
 * call, no deploy.
 */
class AiProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $active = AiProviderSetting::query()->where('active', true)->first();

        if ($active === null) {
            throw new AiProviderException('No active AI provider is configured — set one in the admin panel (Plan §8.7.9).');
        }

        return $active->provider;
    }

    protected function createOpenaiDriver(): AiProvider
    {
        return new OpenAiProvider($this->settingFor(AiProviderSetting::PROVIDER_OPENAI));
    }

    protected function createGroqDriver(): AiProvider
    {
        return new GroqProvider($this->settingFor(AiProviderSetting::PROVIDER_GROQ));
    }

    protected function createClaudeDriver(): AiProvider
    {
        return new ClaudeProvider($this->settingFor(AiProviderSetting::PROVIDER_CLAUDE));
    }

    private function settingFor(string $provider): AiProviderSetting
    {
        return AiProviderSetting::query()->where('provider', $provider)->firstOrFail();
    }
}
