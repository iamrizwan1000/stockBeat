<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\CannedReplyController;
use App\Http\Controllers\Admin\CustomerActionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OpsController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SegmentController;
use App\Http\Controllers\Admin\SupportInboxController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

// Admin panel (Inertia + React + Polaris). Fortify serves /admin/login and
// /admin/logout on the "admin" guard (the app default) — see config/fortify.php.
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/export', [CustomerController::class, 'exportCsv'])->name('customers.export');
    Route::get('customers/{user}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

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

    Route::middleware('admin.write')->group(function () {
        Route::post('customers/{user}/extend-trial', [CustomerActionController::class, 'extendTrial'])->name('customers.extend-trial');
        Route::post('customers/{user}/grant-pro', [CustomerActionController::class, 'grantPro'])->name('customers.grant-pro');
        Route::post('customers/{user}/grant-sms-credits', [CustomerActionController::class, 'grantSmsCredits'])->name('customers.grant-sms-credits');
        Route::post('customers/{user}/force-logout', [CustomerActionController::class, 'forceLogout'])->name('customers.force-logout');
        Route::post('customers/{user}/suspend', [CustomerActionController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{user}/unsuspend', [CustomerActionController::class, 'unsuspend'])->name('customers.unsuspend');
        Route::put('plans/limits/{limit}', [PlanController::class, 'update'])->name('plans.limits.update');

        Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::put('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');

        Route::post('broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
        Route::post('broadcasts/{broadcast}/send-test', [BroadcastController::class, 'sendTest'])->name('broadcasts.send-test');
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
    });
});
