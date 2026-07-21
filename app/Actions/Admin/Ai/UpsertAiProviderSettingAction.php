<?php

namespace App\Actions\Admin\Ai;

use App\Actions\Admin\AuditLogAction;
use App\Models\AdminUser;
use App\Models\AiProviderSetting;
use Illuminate\Validation\ValidationException;

/**
 * Plan §8.7.9: an admin sets/updates one provider's key+model and
 * optionally makes it the single active provider — takes effect on the
 * very next `/assistant/ask` call, no deploy. Mirrors
 * `UpdatePlanLimitAction`'s audit-log discipline exactly.
 */
class UpsertAiProviderSettingAction
{
    public function __construct(
        private readonly AuditLogAction $auditLog,
    ) {}

    public function handle(AdminUser $admin, string $provider, ?string $apiKey, ?string $model, bool $activate): AiProviderSetting
    {
        $setting = AiProviderSetting::query()->firstOrNew(['provider' => $provider]);
        $before = $setting->exists ? ['model' => $setting->model, 'active' => $setting->active] : null;

        if ($apiKey !== null && $apiKey !== '') {
            $setting->api_key = $apiKey;
        }

        if ($model !== null && $model !== '') {
            $setting->model = $model;
        }

        if ($activate && $setting->api_key === null) {
            throw ValidationException::withMessages([
                'api_key' => 'Provide an API key before activating this provider.',
            ]);
        }

        $setting->updated_by = $admin->id;
        $setting->save();

        if ($activate) {
            AiProviderSetting::query()->where('id', '!=', $setting->id)->update(['active' => false]);
            $setting->update(['active' => true]);
        }

        $this->auditLog->handle($admin, 'ai_provider.update', AiProviderSetting::class, $setting->id, $before, [
            'model' => $setting->model,
            'active' => $setting->active,
        ]);

        return $setting;
    }
}
