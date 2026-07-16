<?php

use App\Http\Controllers\Admin\CustomerActionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

// Admin panel (Inertia + React + Polaris). Fortify serves /admin/login and
// /admin/logout on the "admin" guard (the app default) — see config/fortify.php.
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/export', [CustomerController::class, 'exportCsv'])->name('customers.export');
    Route::get('customers/{user}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

    Route::middleware('admin.write')->group(function () {
        Route::post('customers/{user}/extend-trial', [CustomerActionController::class, 'extendTrial'])->name('customers.extend-trial');
        Route::post('customers/{user}/grant-pro', [CustomerActionController::class, 'grantPro'])->name('customers.grant-pro');
        Route::post('customers/{user}/grant-sms-credits', [CustomerActionController::class, 'grantSmsCredits'])->name('customers.grant-sms-credits');
        Route::post('customers/{user}/force-logout', [CustomerActionController::class, 'forceLogout'])->name('customers.force-logout');
        Route::post('customers/{user}/suspend', [CustomerActionController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{user}/unsuspend', [CustomerActionController::class, 'unsuspend'])->name('customers.unsuspend');
        Route::put('plans/limits/{limit}', [PlanController::class, 'update'])->name('plans.limits.update');
    });
});
