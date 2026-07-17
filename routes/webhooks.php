<?php

use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('hooks/woo/{connection}', [WebhookController::class, 'woo'])->name('hooks.woo');
Route::post('hooks/revenuecat', [WebhookController::class, 'revenuecat'])->name('hooks.revenuecat');
Route::post('hooks/email-inbound', [WebhookController::class, 'emailInbound'])->name('hooks.email-inbound');

// OAuth callbacks (Plan §7) — the merchant's browser lands here after
// approving on the platform's own site, so these must be public/GET.
Route::get('hooks/shopify/oauth/callback', [OAuthCallbackController::class, 'shopify'])->name('hooks.shopify.oauth-callback');
Route::get('hooks/ebay/oauth/callback', [OAuthCallbackController::class, 'ebay'])->name('hooks.ebay.oauth-callback');
Route::get('hooks/etsy/oauth/callback', [OAuthCallbackController::class, 'etsy'])->name('hooks.etsy.oauth-callback');

// Order matters: the static `gdpr` route must be registered before the
// dynamic `{connection}` route below, or `{connection}` greedily matches
// the literal segment "gdpr" and fails route-model-binding with a 404.
Route::post('hooks/shopify/gdpr', [WebhookController::class, 'shopifyGdpr'])->name('hooks.shopify.gdpr');
Route::post('hooks/shopify/{connection}', [WebhookController::class, 'shopify'])->name('hooks.shopify');

// eBay's Marketplace Account Deletion (Plan §7.3): eBay sends a GET with a
// challenge_code during setup verification, then real deletion payloads via
// POST — same endpoint handles both per eBay's own spec.
Route::match(['get', 'post'], 'hooks/ebay/account-deletion', [WebhookController::class, 'ebayAccountDeletion'])->name('hooks.ebay.account-deletion');
