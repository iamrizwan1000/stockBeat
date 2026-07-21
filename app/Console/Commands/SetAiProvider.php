<?php

namespace App\Console\Commands;

use App\Models\AiProviderSetting;
use Illuminate\Console\Command;

/**
 * Bootstraps/updates an `ai_provider_settings` row and (optionally) makes
 * it the single active provider (Plan §4.12/§8.7.9). Stand-in for the admin
 * Polaris settings page while that UI is still pending — writes to the
 * exact same table `AiProviderManager` reads, so a real key set this way
 * takes effect immediately, same as it would from the admin panel.
 */
class SetAiProvider extends Command
{
    protected $signature = 'ai:set-provider
        {provider : openai, groq, or claude}
        {--key= : API key for this provider}
        {--model= : Model name, e.g. gpt-4o, llama-3.3-70b-versatile, claude-sonnet-5}
        {--activate : Make this the single active provider (deactivates all others)}';

    protected $description = 'Set or update an AI provider\'s key/model, and optionally activate it';

    public function handle(): int
    {
        $provider = (string) $this->argument('provider');

        if (! in_array($provider, AiProviderSetting::providers(), true)) {
            $this->error('Provider must be one of: '.implode(', ', AiProviderSetting::providers()));

            return self::FAILURE;
        }

        $setting = AiProviderSetting::query()->firstOrNew(['provider' => $provider]);

        if ($this->option('key') !== null) {
            $setting->api_key = $this->option('key');
        }

        if ($this->option('model') !== null) {
            $setting->model = $this->option('model');
        }

        if (! $setting->exists && $setting->api_key === null) {
            $this->error('Provide --key when creating a provider for the first time.');

            return self::FAILURE;
        }

        $setting->save();

        if ($this->option('activate')) {
            AiProviderSetting::query()->where('provider', '!=', $provider)->update(['active' => false]);
            $setting->update(['active' => true]);
            $this->info("{$provider} is now the active AI provider.");
        } else {
            $this->info("{$provider} settings saved.");
        }

        return self::SUCCESS;
    }
}
