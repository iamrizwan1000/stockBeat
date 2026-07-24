# Setting Up RevenueCat + Google Play Console for StockBeat Billing

This is the **business/ops setup checklist** — what you personally need to configure in the Google Play Console and the RevenueCat dashboard so the billing code (already built, see `mobile/billing-topup-guide.md`) actually processes real purchases. Nothing here is a code change; it's dashboard configuration plus two `.env` values.

If you're only supporting Android at launch, skip the App Store–specific notes (marked accordingly) — everything else still applies since RevenueCat is the cross-platform layer either way.

---

## 1. Google Play Console

### 1.1 App & Play Billing prerequisites

- A Google Play Developer account ($25 one-time registration fee if you don't already have one) and the app created in Play Console, even if it's still in internal testing.
- The app must have at least one APK/AAB uploaded to an internal testing track before Play Billing products can be tested (Google's own requirement — you can't test IAPs against an app that's never been uploaded).
- **A Google Cloud service account with API access to Play Console**, used later to link RevenueCat (§2.2) — create this under Play Console → Setup → API access → "Create new service account" (it walks you to Google Cloud Console to finish creation), then grant it **Finance** permissions back in Play Console for this specific app.

### 1.2 Create the 5 subscription products

Play Console → your app → Monetize → Products → **Subscriptions**. Create one subscription product per tier, each with the base plans/prices below. The **product ID you type here must exactly match** the whitelist in `ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS` — get these wrong and a real purchase will silently grant nothing (no error visible to the buyer or to you):

| Product ID (exact) | Base plan | Price |
|---|---|---|
| `starter_monthly` | Monthly, auto-renewing | $5.99 |
| `pro_monthly` | Monthly, auto-renewing | $17.99 |
| `pro_yearly` | Yearly, auto-renewing | $172.99 |
| `premium_monthly` | Monthly, auto-renewing | $44.99 |
| `premium_yearly` | Yearly, auto-renewing | $429.99 |

(`pro_monthly`/`pro_yearly` and `premium_monthly`/`premium_yearly` can be modeled as two base plans under one subscription "product" per tier in Play Console's newer subscription model, or as fully separate products — either is fine, RevenueCat/our webhook only ever sees the product id string, not Play Console's internal grouping.)

Set regional pricing however you want per market — the *product ID* is what's load-bearing here, not the price (the price shown to the buyer always comes from the store, never from this backend).

### 1.3 Create the 4 consumable products

Play Console → Monetize → Products → **In-app products** (not Subscriptions — these are one-time, and must be marked so RevenueCat can flag them as consumable):

| Product ID (exact) | Price |
|---|---|
| `sms_100` | $2.99 |
| `sms_500` | $9.99 |
| `ai_50` | $4.99 |
| `ai_200` | $14.99 |

**Managed products in Play Billing are consumable by default from the API's perspective** — the RevenueCat SDK handles calling `consumeAsync` after a successful purchase automatically (as long as the product is configured as "consumable" in RevenueCat's own dashboard, §2.3 below), so there's nothing extra to configure in Play Console itself beyond creating these as in-app products with the exact IDs above.

### 1.4 License testing (before going live)

Play Console → Setup → License testing → add your own Google account(s) as license testers. Purchases made by a license-testing account go through the real Play Billing flow but aren't charged — this is how you test the entire pipeline (purchase → RevenueCat webhook → this backend → `/me` reflecting the new plan) without spending real money. Do this before ever testing against production credentials.

---

## 2. RevenueCat Dashboard

### 2.1 Project & app setup

1. Create a RevenueCat account/project if you don't already have one.
2. Add an app under that project for **Google Play** — this is where you'll paste the service account credentials from §1.1 (RevenueCat → your app → Google Play Store settings → upload the service account JSON key, and enter your Play Console package name). This link is what lets RevenueCat verify Android purchase receipts server-side and (optionally, recommended) receive Play's Real-Time Developer Notifications for near-instant renewal/cancellation detection instead of polling.
3. If you're also supporting iOS, add a second app under the same project for **App Store** (needs your App Store Connect shared secret — App Store Connect → your app → App Information → App-Specific Shared Secret).
4. Each platform app gets its own **public SDK key** (RevenueCat → Project settings → API keys → "Public app-specific API key") — this goes into the *mobile app's* RevenueCat SDK configuration, not this backend's `.env`. Keep the Android and iOS public keys straight; using the wrong one silently breaks purchases on that platform.

### 2.2 Products & Offerings

Add all 9 product IDs from §1.2/§1.3 to RevenueCat (Products tab) — for each, RevenueCat auto-detects it from the linked store once the store-side product exists (§1.2/§1.3 must be done first, or RevenueCat won't find them).

- **Mark `sms_100`/`sms_500`/`ai_50`/`ai_200` as non-subscription (consumable) products** — this is the toggle that makes the SDK auto-consume them after purchase (§1.3's note).
- Build an **Offering** (RevenueCat's "current" offering is what `Purchases.getOfferings()` returns to the app) containing **Packages** for the 5 subscription products — this is what the mobile app's native paywall renders from, so the app never needs its own hardcoded pricing table (`billing-topup-guide.md` §3 assumes this).
- The 4 consumables are typically **not** added to the subscription Offering/paywall — the app fetches their catalog (name/credits/price) from this backend's `GET /me` (`sms_topup_packs`/`ai_topup_packs`) instead and purchases them via a direct SDK product-purchase call, not through the Offering/paywall UI.

**RevenueCat "Entitlements" (the dashboard concept) are optional here and not required** — this backend doesn't read RevenueCat's entitlement identifiers at all; `ProcessRevenueCatEventAction` maps a raw `product_id` straight to a `Plan::key` itself. You can still set up RC Entitlements for RevenueCat's own dashboard analytics/reporting convenience if you want, but nothing server-side depends on them.

### 2.3 Webhook — connects RevenueCat to this backend

RevenueCat → Project settings → **Integrations → Webhooks** → add one:

- **URL:** `https://<your-production-domain>/hooks/revenuecat`
- **Authorization header:** a value **you generate yourself** (this is not something RevenueCat issues — see the code comment in `WebhookController::revenuecat()`). Generate a long random string, put it in both:
  - RevenueCat's webhook "Authorization header value" field, and
  - this app's `.env` as `REVENUECAT_WEBHOOK_SECRET`.
  These must match exactly (constant-time compared server-side) or every webhook silently 401s.
- **Event types:** enable at least `INITIAL_PURCHASE`, `RENEWAL`, `PRODUCT_CHANGE`, `UNCANCELLATION`, `CANCELLATION`, `BILLING_ISSUE`, `EXPIRATION`, `NON_RENEWING_PURCHASE` — these are the ones `ProcessRevenueCatEventAction` actually handles; enabling others is harmless (unhandled types are a no-op) but unnecessary.

### 2.4 Secret API key — for this backend's server-to-server calls

RevenueCat → Project settings → **API keys** → **Secret API key** (different from the public SDK keys in §2.1 — this one authorizes server-to-server REST calls, never ships in the mobile app). Put it in `.env` as `REVENUECAT_SECRET_API_KEY`. This is what `POST /billing/sync` uses to pull a subscriber's live state directly from RevenueCat's API (the restore-purchases path, `SyncRevenueCatSubscriberAction`) — without it, that endpoint fails open (returns existing entitlements unchanged) rather than erroring, so purchases still work via the webhook, but Restore Purchases on a new device won't function correctly.

### 2.5 Sandbox/test mode

RevenueCat automatically detects sandbox purchases (from a Play Console license tester or an App Store Sandbox account) and tags them accordingly in its dashboard — you don't need a separate RevenueCat project for testing. Just confirm real webhook events are actually arriving (RevenueCat's dashboard shows a webhook delivery log with success/failure per event) before trusting a test purchase end-to-end.

---

## 3. End-to-end verification checklist

Once both sides are configured:

1. Set `REVENUECAT_WEBHOOK_SECRET` and `REVENUECAT_SECRET_API_KEY` in production `.env`, then `php artisan config:cache` (a stale cached config is a common reason a freshly-set env var appears to do nothing — see `deployment.md`).
2. From a license-tester Android account, purchase `pro_monthly` in the app.
3. Check RevenueCat's dashboard webhook log — confirm `INITIAL_PURCHASE` shows a successful (200) delivery to `/hooks/revenuecat`.
4. Confirm `GET /me` for that test account now shows `plan: "pro"`, `subscription_status: "active"`.
5. Purchase `sms_100` as the same tester — confirm `sms_balance` rises by exactly 100 and a new `NON_RENEWING_PURCHASE` webhook delivery shows success.
6. Uninstall/reinstall the app (or use a second test device) and tap **Restore Purchases** — confirm `POST /billing/sync` correctly re-links the subscription without needing a fresh webhook.

If step 3 or 5 shows a failed webhook delivery in RevenueCat's log, the `Authorization` header value is the most common culprit — re-check it matches `REVENUECAT_WEBHOOK_SECRET` byte-for-byte (no trailing whitespace, no accidental quotes).

---

## See also

- `mobile/billing-topup-guide.md` — what the backend/app actually do with all of this once it's wired up.
- `deployment.md` — where `REVENUECAT_WEBHOOK_SECRET`/`REVENUECAT_SECRET_API_KEY` fit into the rest of the required `.env` configuration.
