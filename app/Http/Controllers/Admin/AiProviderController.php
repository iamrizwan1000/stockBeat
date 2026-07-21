<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Ai\UpsertAiProviderSettingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiProviderSettingRequest;
use App\Models\AdminUser;
use App\Models\AiProviderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan §8.7.9 — single global active-provider switch + per-provider
 * encrypted key/model. The API key itself is never sent back to the
 * browser (`AiProviderSetting`'s `#[Hidden(['api_key'])]`) — only whether
 * one is currently set (`has_key`).
 */
class AiProviderController extends Controller
{
    public function index(): Response
    {
        $settings = AiProviderSetting::query()->get()->keyBy('provider');

        $providers = collect(AiProviderSetting::providers())->map(function (string $provider) use ($settings) {
            $setting = $settings->get($provider);

            return [
                'provider' => $provider,
                'model' => $setting?->model,
                'active' => (bool) $setting?->active,
                'has_key' => $setting?->api_key !== null,
                'updated_at' => $setting?->updated_at,
            ];
        })->values();

        return Inertia::render('admin/ai-assistant/index', ['providers' => $providers]);
    }

    public function update(UpdateAiProviderSettingRequest $request, string $provider, UpsertAiProviderSettingAction $action): RedirectResponse
    {
        $action->handle(
            $this->admin($request),
            $provider,
            $request->validated('api_key'),
            $request->validated('model'),
            (bool) $request->validated('activate'),
        );

        return back()->with('status', 'AI provider settings saved.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('admin');

        return $admin;
    }
}
