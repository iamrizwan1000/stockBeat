<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('hooks/woo/{connection}', [WebhookController::class, 'woo'])->name('hooks.woo');
Route::post('hooks/revenuecat', [WebhookController::class, 'revenuecat'])->name('hooks.revenuecat');
Route::post('hooks/email-inbound', [WebhookController::class, 'emailInbound'])->name('hooks.email-inbound');
