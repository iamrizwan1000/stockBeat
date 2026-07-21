<?php

use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AiProviderController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\CannedReplyController;
use App\Http\Controllers\Admin\ContentBlockController;
use App\Http\Controllers\Admin\CustomerActionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\OpsController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PromoCampaignController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SegmentController;
use App\Http\Controllers\Admin\SmsTopupPackController;
use App\Http\Controllers\Admin\SupportInboxController;
use App\Models\AiProviderSetting;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

// Admin panel (Inertia + React + Polaris). Fortify serves /admin/login and
// /admin/logout on the "admin" guard (the app default) — see config/fortify.php.
Route::middleware(['auth', 'admin.2fa'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/export', [CustomerController::class, 'exportCsv'])->name('customers.export');
    Route::get('customers/{user}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

    Route::get('feature-flags', [FeatureFlagController::class, 'index'])->name('feature-flags.index');

    Route::get('ai-assistant', [AiProviderController::class, 'index'])->name('ai-assistant.index');

    Route::get('promotions', [PromoCampaignController::class, 'index'])->name('promotions.index');
    Route::get('promotions/{promoCampaign}', [PromoCampaignController::class, 'show'])->name('promotions.show');

    Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
    Route::post('segments/preview-count', [SegmentController::class, 'previewCount'])->name('segments.preview-count');

    Route::get('broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
    Route::get('broadcasts/create', [BroadcastController::class, 'create'])->name('broadcasts.create');
    Route::get('broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('broadcasts.show');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

    Route::get('ops', [OpsController::class, 'index'])->name('ops.index');

    Route::get('support', [SupportInboxController::class, 'index'])->name('support.index');
    Route::get('support/{thread}', [SupportInboxController::class, 'show'])->name('support.show');

    Route::get('canned-replies', [CannedReplyController::class, 'index'])->name('canned-replies.index');

    Route::get('team', [AdminUserController::class, 'index'])->name('team.index');
    Route::get('audit-log', [AdminAuditLogController::class, 'index'])->name('audit-log.index');

    // Self-service — any admin manages their own 2FA, not gated by `admin.write`
    // (that middleware is for actions taken on *other* records).
    Route::get('security', [SecurityController::class, 'index'])->name('security.index');

    Route::middleware('admin.write')->group(function () {
        Route::post('customers/{user}/extend-trial', [CustomerActionController::class, 'extendTrial'])->name('customers.extend-trial');
        Route::post('customers/{user}/grant-pro', [CustomerActionController::class, 'grantPro'])->name('customers.grant-pro');
        Route::post('customers/{user}/grant-sms-credits', [CustomerActionController::class, 'grantSmsCredits'])->name('customers.grant-sms-credits');
        Route::post('customers/{user}/grant-ai-credits', [CustomerActionController::class, 'grantAiCredits'])->name('customers.grant-ai-credits');
        Route::post('customers/{user}/force-logout', [CustomerActionController::class, 'forceLogout'])->name('customers.force-logout');
        Route::post('customers/{user}/suspend', [CustomerActionController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{user}/unsuspend', [CustomerActionController::class, 'unsuspend'])->name('customers.unsuspend');
        Route::put('plans/limits/{limit}', [PlanController::class, 'update'])->name('plans.limits.update');

        Route::post('plans/sms-packs', [SmsTopupPackController::class, 'store'])->name('plans.sms-packs.store');
        Route::put('plans/sms-packs/{smsPack}', [SmsTopupPackController::class, 'update'])->name('plans.sms-packs.update');
        Route::delete('plans/sms-packs/{smsPack}', [SmsTopupPackController::class, 'destroy'])->name('plans.sms-packs.destroy');

        Route::post('plans/content-blocks', [ContentBlockController::class, 'store'])->name('plans.content-blocks.store');
        Route::put('plans/content-blocks/{contentBlock}', [ContentBlockController::class, 'update'])->name('plans.content-blocks.update');
        Route::delete('plans/content-blocks/{contentBlock}', [ContentBlockController::class, 'destroy'])->name('plans.content-blocks.destroy');

        Route::post('feature-flags', [FeatureFlagController::class, 'store'])->name('feature-flags.store');
        Route::put('feature-flags/{featureFlag}', [FeatureFlagController::class, 'update'])->name('feature-flags.update');
        Route::delete('feature-flags/{featureFlag}', [FeatureFlagController::class, 'destroy'])->name('feature-flags.destroy');

        Route::put('ai-assistant/{provider}', [AiProviderController::class, 'update'])
            ->whereIn('provider', AiProviderSetting::providers())
            ->name('ai-assistant.update');

        Route::post('promotions', [PromoCampaignController::class, 'store'])->name('promotions.store');
        Route::put('promotions/{promoCampaign}', [PromoCampaignController::class, 'update'])->name('promotions.update');
        Route::delete('promotions/{promoCampaign}', [PromoCampaignController::class, 'destroy'])->name('promotions.destroy');
        Route::post('promotions/{promoCampaign}/apply', [PromoCampaignController::class, 'applyServerComp'])->name('promotions.apply');

        Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::put('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');

        Route::post('broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
        Route::post('broadcasts/{broadcast}/send-test', [BroadcastController::class, 'sendTest'])->name('broadcasts.send-test');
        Route::post('broadcasts/{broadcast}/approve', [BroadcastController::class, 'approve'])->name('broadcasts.approve');
        Route::post('broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])->name('broadcasts.send');

        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        Route::put('ops/config', [OpsController::class, 'updateConfig'])->name('ops.config.update');

        Route::post('support/{thread}/reply', [SupportInboxController::class, 'reply'])->name('support.reply');
        Route::post('support/{thread}/notes', [SupportInboxController::class, 'addNote'])->name('support.notes.store');
        Route::post('support/{thread}/assign', [SupportInboxController::class, 'assign'])->name('support.assign');
        Route::post('support/{thread}/resolve', [SupportInboxController::class, 'resolve'])->name('support.resolve');

        Route::post('canned-replies', [CannedReplyController::class, 'store'])->name('canned-replies.store');
        Route::put('canned-replies/{cannedReply}', [CannedReplyController::class, 'update'])->name('canned-replies.update');
        Route::delete('canned-replies/{cannedReply}', [CannedReplyController::class, 'destroy'])->name('canned-replies.destroy');

        Route::post('team', [AdminUserController::class, 'store'])->name('team.store');
        Route::put('team/{adminUser}/role', [AdminUserController::class, 'updateRole'])->name('team.update-role');
        Route::post('team/{adminUser}/reset-2fa', [AdminUserController::class, 'resetTwoFactor'])->name('team.reset-2fa');
        Route::delete('team/{adminUser}', [AdminUserController::class, 'destroy'])->name('team.destroy');
    });
});
