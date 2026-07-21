<?php

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AiProviderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the AI Assistant settings page requires admin authentication', function () {
    test()->get('/admin/ai-assistant')->assertRedirect('/admin/login');
});

test('an admin can set a provider key/model and activate it, logging an audit entry', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->put('/admin/ai-assistant/groq', [
            'api_key' => 'gsk_real_test_key',
            'model' => 'llama-3.3-70b-versatile',
            'activate' => true,
        ])
        ->assertRedirect();

    $setting = AiProviderSetting::query()->where('provider', 'groq')->firstOrFail();
    expect($setting->active)->toBeTrue();
    expect($setting->model)->toBe('llama-3.3-70b-versatile');
    expect($setting->api_key)->toBe('gsk_real_test_key'); // decrypted on read, encrypted at rest
    expect(AdminAuditLog::query()->where('action', 'ai_provider.update')->where('target_id', $setting->id)->exists())->toBeTrue();
});

test('activating a new provider deactivates every other provider', function () {
    $admin = AdminUser::factory()->create();
    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_CLAUDE, 'active' => true]);

    test()->actingAs($admin, 'admin')
        ->put('/admin/ai-assistant/openai', [
            'api_key' => 'sk-real-test-key',
            'model' => 'gpt-4o',
            'activate' => true,
        ])
        ->assertRedirect();

    expect(AiProviderSetting::query()->where('provider', 'openai')->value('active'))->toBeTrue();
    expect(AiProviderSetting::query()->where('provider', AiProviderSetting::PROVIDER_CLAUDE)->value('active'))->toBeFalse();
});

test('activating without ever having set a key is rejected', function () {
    $admin = AdminUser::factory()->create();

    test()->actingAs($admin, 'admin')
        ->put('/admin/ai-assistant/claude', ['activate' => true])
        ->assertSessionHasErrors('api_key');

    expect(AiProviderSetting::query()->where('provider', 'claude')->value('active'))->not->toBeTrue();
});

test('the api key returned to the browser is never the real key, only whether one is set', function () {
    $admin = AdminUser::factory()->create();
    AiProviderSetting::factory()->create(['provider' => AiProviderSetting::PROVIDER_GROQ, 'api_key' => 'super-secret']);

    $response = test()->actingAs($admin, 'admin')->get('/admin/ai-assistant');

    $response->assertOk();
    $page = $response->viewData('page');
    $groq = collect($page['props']['providers'])->firstWhere('provider', 'groq');

    expect($groq['has_key'])->toBeTrue();
    expect(json_encode($groq))->not->toContain('super-secret');
});
