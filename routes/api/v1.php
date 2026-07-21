<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReplyTemplateController;
use App\Http\Controllers\Api\V1\RuleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\ThreadController;
use App\Models\StoreConnection;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('otp/request', [OtpController::class, 'request'])
        ->middleware('throttle:otp-request')
        ->name('otp.request');

    Route::post('otp/verify', [OtpController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');
});

// Public/unauthenticated — must be checkable before the app has a token (Plan §17.7).
Route::get('config', [ConfigController::class, 'show'])->name('config');

Route::middleware(['auth:sanctum', 'user.not_suspended', 'team.not_suspended'])->group(function () {
    Route::post('auth/logout', [SessionController::class, 'logout'])->name('auth.logout');
    Route::post('auth/logout-all', [SessionController::class, 'logoutAll'])->name('auth.logout-all');

    Route::post('profile/setup', [ProfileController::class, 'setup'])->name('profile.setup');
    Route::get('me', [MeController::class, 'show'])->name('me');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');

    Route::get('settings/notifications', [SettingsController::class, 'showNotificationPreferences'])->name('settings.notifications.show');
    Route::put('settings/notifications', [SettingsController::class, 'updateNotificationPreferences'])->name('settings.notifications.update');

    Route::post('account/data-export', [AccountController::class, 'requestDataExport'])->name('account.data-export');
    Route::post('account/delete-request', [AccountController::class, 'requestDeletion'])->name('account.delete-request');

    Route::post('connections/{platform}/start', [ConnectionController::class, 'start'])
        ->whereIn('platform', [
            StoreConnection::PLATFORM_SHOPIFY,
            StoreConnection::PLATFORM_WOO,
            StoreConnection::PLATFORM_EBAY,
            StoreConnection::PLATFORM_ETSY,
            StoreConnection::PLATFORM_AMAZON,
            StoreConnection::PLATFORM_TIKTOK,
        ])
        ->middleware('team.role:owner,manager')
        ->name('connections.start');
    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::get('connections/{connection}/health', [ConnectionController::class, 'health'])->name('connections.health');
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])
        ->middleware('team.role:owner,manager')
        ->name('connections.destroy');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])
        ->middleware('team.role:owner,manager')
        ->name('orders.notes.store');
    Route::post('orders/{order}/tags', [OrderController::class, 'updateTags'])
        ->middleware('team.role:owner,manager')
        ->name('orders.tags.update');
    Route::post('orders/{order}/snooze', [OrderController::class, 'snooze'])
        ->middleware('team.role:owner,manager')
        ->name('orders.snooze');
    Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])
        ->middleware('team.role:owner,manager')
        ->name('orders.fulfill');
    Route::post('orders/{order}/refund', [OrderController::class, 'refund'])
        ->middleware('team.role:owner,manager')
        ->name('orders.refund');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
        ->middleware('team.role:owner,manager')
        ->name('orders.cancel');
    Route::get('orders/{order}/packing-slip', [OrderController::class, 'packingSlip'])->name('orders.packing-slip');
    Route::post('orders/{order}/message', [OrderController::class, 'message'])
        ->middleware('team.role:owner,manager')
        ->name('orders.message');

    Route::get('rules', [RuleController::class, 'index'])->name('rules.index');
    Route::post('rules', [RuleController::class, 'store'])
        ->middleware('team.role:owner,manager')
        ->name('rules.store');
    Route::put('rules/{rule}', [RuleController::class, 'update'])
        ->middleware('team.role:owner,manager')
        ->name('rules.update');
    Route::post('rules/{rule}/test', [RuleController::class, 'test'])
        ->middleware('team.role:owner,manager')
        ->name('rules.test');
    Route::get('rules/{rule}/executions', [RuleController::class, 'executions'])->name('rules.executions');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('analytics/summary', [AnalyticsController::class, 'summary'])->name('analytics.summary');
    Route::get('analytics/products', [AnalyticsController::class, 'products'])->name('analytics.products');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::put('products/{product}/cost-price', [ProductController::class, 'updateCostPrice'])
        ->middleware('team.role:owner,manager')
        ->name('products.cost-price.update');

    Route::post('assistant/ask', [AssistantController::class, 'ask'])->name('assistant.ask');
    Route::get('assistant/conversations', [AssistantController::class, 'index'])->name('assistant.conversations.index');
    Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'show'])->name('assistant.conversations.show');
    Route::post('assistant/rule-draft', [AssistantController::class, 'ruleDraft'])->name('assistant.rule-draft');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

    Route::get('support/thread', [SupportController::class, 'show'])->name('support.thread');
    Route::post('support/messages', [SupportController::class, 'store'])->name('support.messages.store');
    Route::post('support/csat', [SupportController::class, 'submitCsat'])->name('support.csat');

    Route::get('threads', [ThreadController::class, 'index'])->name('threads.index');
    Route::get('threads/{thread}/messages', [ThreadController::class, 'messages'])->name('threads.messages');
    Route::post('threads/{thread}/messages', [ThreadController::class, 'sendMessage'])
        ->middleware('team.role:owner,manager')
        ->name('threads.messages.store');
    Route::post('threads/{thread}/assign', [ThreadController::class, 'assign'])
        ->middleware('team.role:owner,manager')
        ->name('threads.assign');

    Route::get('reply-templates', [ReplyTemplateController::class, 'index'])->name('reply-templates.index');
    Route::post('reply-templates', [ReplyTemplateController::class, 'store'])
        ->middleware('team.role:owner,manager')
        ->name('reply-templates.store');
    Route::put('reply-templates/{replyTemplate}', [ReplyTemplateController::class, 'update'])
        ->middleware('team.role:owner,manager')
        ->name('reply-templates.update');
    Route::delete('reply-templates/{replyTemplate}', [ReplyTemplateController::class, 'destroy'])
        ->middleware('team.role:owner,manager')
        ->name('reply-templates.destroy');

    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team/invite', [TeamController::class, 'invite'])
        ->middleware('team.role:owner,manager')
        ->name('team.invite');
    Route::put('team/{member}', [TeamController::class, 'update'])
        ->middleware('team.role:owner,manager')
        ->name('team.update');
});
