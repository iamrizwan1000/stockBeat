# StockBeat Mobile — Subscription & Top-Up Integration Guide

Everything described here is **built and real** on the backend — this isn't a spec for future work, it's how the system actually behaves today. `settings-api-reference.md`/`auth-api-reference.md`/`usage-api-reference.md` are the field-by-field references; this doc is the **end-to-end story** — what happens, in what order, across the app + store + RevenueCat + this API, for a purchase or top-up. Read this first if you're wiring up billing from scratch, then use the other docs for exact request/response shapes.

---

## 1. The moving parts

```
Mobile app (RevenueCat SDK)
   │  purchase / restore
   ▼
App Store / Play Store  ──────────────►  RevenueCat
   ▲                                         │
   │ receipt validation, renewal tracking     │ webhook (async, usually seconds)
   │                                         ▼
   └── entitlement check (local, instant)   POST /hooks/revenuecat  (this backend)
                                                │
                                                ▼
                                     subscriptions / sms_ledger / ai_usage_ledger
                                                │
                                                ▼
                                     GET /me · GET /billing/entitlements · GET /usage/summary
```

**This backend is the single source of truth for entitlements** — never gate a feature purely on what the RevenueCat SDK reports locally. The SDK's local `CustomerInfo` is useful for an *instant* unlock right after a purchase (good UX — no spinner), but the app must always reconcile against this API afterward, because:
- The webhook that actually updates `subscriptions`/`sms_ledger`/`ai_usage_ledger` is asynchronous — it can lag the purchase by a few seconds.
- Only this API's `GET /me` (or `GET /billing/entitlements`) reflects what every other endpoint (rules, orders, AI assistant) will actually enforce.

**Identity linking:** RevenueCat's `app_user_id` **is** this app's numeric `User.id`, sent as a string. Call the RevenueCat SDK's `logIn(userId)` (or configure it with that app_user_id at launch) right after auth — this is how `POST /hooks/revenuecat` and `POST /billing/sync` resolve a RevenueCat event back to a specific team.

---

## 2. Products — what's real, what mobile needs configured

Five renewing subscription products, four consumables. **These exact identifiers are load-bearing** — they're a hardcoded whitelist server-side (`ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS`, plus the `sms_topup_packs`/`ai_topup_packs` DB tables), not something the app can invent or rename.

| Product ID | Type | Grants | List price (Plan §5/§6, subject to store-region pricing) |
|---|---|---|---|
| `starter_monthly` | Auto-renewing subscription | Starter tier | $5.99/mo |
| `pro_monthly` | Auto-renewing subscription | Pro tier | $17.99/mo |
| `pro_yearly` | Auto-renewing subscription | Pro tier | $172.99/yr |
| `premium_monthly` | Auto-renewing subscription | Premium tier | $44.99/mo |
| `premium_yearly` | Auto-renewing subscription | Premium tier | $429.99/yr |
| `sms_100` | Consumable (non-renewing) | +100 SMS credits, non-expiring | $2.99 |
| `sms_500` | Consumable (non-renewing) | +500 SMS credits, non-expiring | $9.99 |
| `ai_50` | Consumable (non-renewing) | +50 AI questions, this calendar month only | $4.99 |
| `ai_200` | Consumable (non-renewing) | +200 AI questions, this calendar month only | $14.99 |

There is **no Free product** — Free is simply the absence of an active subscription (`subscription_status: null`).

**Mobile doesn't hardcode the top-up catalog** (name/credits/price) — it's served live from `GET /me`'s `sms_topup_packs`/`ai_topup_packs` arrays (admin-editable, so a price correction or a new pack never needs an app release). Mobile **does** hardcode the 5 subscription product IDs to build the RevenueCat paywall/purchase UI against (RevenueCat's own Offerings feature is the recommended way to do this without a second hardcoded list — see the setup guide's Offerings section).

---

## 3. Subscription purchase flow — step by step

1. User taps upgrade → app opens the **RevenueCat SDK's native paywall/purchase sheet** directly (`Purchases.getOfferings()` → present the packages). **Never build a custom pricing table that calls an endpoint on this API** — there isn't one; prices live in App Store Connect / Play Console via RevenueCat.
2. Store handles the purchase (Face ID / Play Billing sheet). RevenueCat SDK resolves locally — `CustomerInfo.entitlements` flips immediately, safe to use for an optimistic "you're in!" screen transition.
3. In the background (usually 1–10s later, occasionally longer), RevenueCat fires a webhook (`INITIAL_PURCHASE`) to `POST /hooks/revenuecat` on this backend. `ProcessRevenueCatEventAction` runs the whole state machine in one pass:
   - Creates/updates the team's `Subscription` row: `status = active`, `plan_key` set from the product ID, `expires_at` from the payload.
   - Reverses any downgrade freeze (paused stores, auto-disabled rules, suspended seats) if the team had previously lapsed — restored oldest-first up to the new plan's limits.
   - **Grants the new plan's monthly SMS allotment immediately** (`GrantMonthlySmsCreditsAction` — see §6 below), not on the next day's reconciliation job.
   - Appends the event to `subscription_events` (LTV/timeline history only — never feeds back into entitlement logic).
4. **Mobile's job after step 2:** poll `GET /me` (or `GET /billing/entitlements`, which is lighter) for up to **~10–15 seconds**, a few retries, rather than assuming the very next call already reflects the new plan. Stop polling as soon as `entitlements.plan` matches what was just purchased, or the SDK-optimistic state times out gracefully back to a "still processing, check back shortly" state (this should essentially never be visibly hit in practice — the webhook is fast — but don't crash or dead-end if it is).
5. Once `GET /me` reflects the new plan, treat that as ground truth for every gated feature in the app — stop reading anything from the SDK's `CustomerInfo` beyond that point.

### Renewal / plan change / cancellation / billing issue / expiration

These all arrive the same way — a RevenueCat webhook the app never directly participates in, just observes via the next `GET /me`:

| RevenueCat event | What changes server-side | What the app should show |
|---|---|---|
| `RENEWAL` | Same as `INITIAL_PURCHASE` — `status=active`, `expires_at` extended, monthly SMS grant fires again (idempotent — a no-op if already granted this calendar month) | Nothing special — this is invisible unless the user is actively looking at the Subscription screen, which should just re-fetch on focus |
| `PRODUCT_CHANGE` | `plan_key` moves to the new tier (e.g. Pro → Premium); same freeze-reversal + SMS-grant logic as a fresh purchase | Plan badge updates on next `/me` |
| `UNCANCELLATION` | Auto-renew turned back on before it lapsed — same reactivation path as a fresh purchase | Same as above |
| `CANCELLATION` | **Does not change `status`** — this only means auto-renew was turned off; the subscription stays fully entitled until it actually expires | Show "Renews [date]" or a soft "won't auto-renew" hint if you want, but don't downgrade the UI yet — access is unaffected until `EXPIRATION` |
| `BILLING_ISSUE` | `status → grace`. Still entitled (`Subscription::isEntitled()` treats grace as active) | Warning banner: "There's a problem with your payment method" + deep link to the platform's own subscription management (fixing a card happens there, not in this app) |
| `EXPIRATION` | `status → expired`, entitlements revert to Free, downgrade freeze applies (stores paused / rules auto-disabled / seats suspended oldest-first, reversible on re-upgrade) | Treat as Free — show the upgrade paywall as primary content |

**Manage / cancel subscription:** deep link to the native App Store / Play Store subscription management screen. This app has no in-house cancel flow (App Store/Play policy — cancellation must go through the platform, not a third-party UI).

### Restore purchases

Call the RevenueCat SDK's own restore call, then **`POST /billing/sync`** (not just `GET /me`) — this is the one moment a webhook can't be relied on, since a restore on a brand-new device doesn't necessarily fire one. `POST /billing/sync`:
- Links this device's RevenueCat identity (`rc_app_user_id`) to the team.
- Pulls the subscriber's *current* state directly from RevenueCat's REST API (server-to-server, using `REVENUECAT_SECRET_API_KEY`) rather than waiting.
- **Fails open** — if RevenueCat itself is unreachable, returns `200` with whatever was already on file rather than erroring (never lock a paying user out over a RevenueCat outage). There's no "sync failed" signal distinct from "nothing changed" — if you need to confirm a specific purchase landed, compare `plan`/`subscription_status` before/after, or poll.
- **Only reconciles the subscription — never SMS/AI top-ups.** Apple/Google's restore rules explicitly exclude consumables; a top-up is credited exactly once, at time of purchase, webhook-only. Don't call this expecting a lost top-up to reappear — there's nothing to restore there by platform design.

### Trial

Every new team gets a 7-day trial **on the Premium tier** (the full feature set, so a trialing seller sees everything before choosing a paid tier), granted server-side the moment `POST /profile/setup` completes — nothing for the app to purchase or call to start it. As of this pass, the trial also immediately grants Premium's full monthly SMS allotment (500 credits) at the same moment, not on a later job run — a fresh trial signup's `GET /me` already shows `sms_balance: 500`.

`subscription_status: "trial"` + `trial_ends_at` drive the UI: "Trial ends [date]" + upgrade CTA. Day-3/day-10-equivalent win-back push reminders (`trial_reminder` notification type) fire automatically server-side — nothing for the app to trigger, just handle the notification tap (→ Subscription screen).

---

## 4. SMS top-up — step by step

1. `SubscriptionScreen` lists `GET /me`'s `sms_topup_packs` (currently seeded: 100 credits/$2.99, 500 credits/$9.99 — admin-editable, don't hardcode). Hide the section entirely if the array is empty.
2. Tap a pack → RevenueCat SDK purchase call using that pack's `key` (`sms_100`/`sms_500`) as the product identifier — this is a **consumable, non-renewing** purchase, a different SDK call shape than the subscription paywall.
3. On purchase completion, poll `GET /me` and watch `entitlements.sms_balance` — same "poll for ~10-15s" pattern as a subscription purchase. Server-side: `ProcessRevenueCatEventAction` sees `type: NON_RENEWING_PURCHASE` with a `product_id` matching an `SmsTopupPack` row, and credits `sms_ledger` with `reason: topup_iap`, `delta: <pack's sms_credits>` — additive to whatever balance already existed (top-up credit never expires and never resets).
4. **Idempotency is already handled server-side** — a redelivered webhook for the same RevenueCat event id is a guaranteed no-op (`revenuecat_events` table, checked before any credit happens). Nothing for mobile to de-duplicate.

---

## 5. AI question top-up — step by step

Identical mechanics to SMS, different ledger and one important semantic difference: **an AI top-up only raises *this calendar month's* cap — it does not carry over.**

1. `GET /me`'s `ai_topup_packs` (seeded: 50 questions/$4.99, 200 questions/$14.99). Same "hide if empty" rule.
2. Tap a pack → RevenueCat purchase with `key` (`ai_50`/`ai_200`).
3. Poll `GET /me`, watch `entitlements.ai_questions_remaining` rise. Server-side: `ai_usage_ledger` gets a `topup_iap` row; `ai_questions_remaining` = plan's `ai_questions_monthly` **plus this calendar month's `topup_iap` sum**, minus questions asked this month. On the 1st of next month the top-up's effect is gone — this is a deliberate scope simplification (documented in `AiUsageLedger`'s docblock), the same one `emails_remaining` and (as of this pass) the SMS monthly grant share: no bucket-separated "never expires" vs. "expires monthly" ledger accounting exists yet.
4. **Where to surface this in the UI, per `ai-flow-screens.md`:** a "Buy more questions" entry point once `ai_questions_remaining` gets low (e.g. under 10) or hits 0, **and** directly from the 422 the Ask AI screen gets when the quota is genuinely exhausted (`"You've used all {N} AI questions included in your plan this month."`) — a merchant mid-question should have a one-tap path to fix it, not a dead end.

---

## 6. What "remaining"/"balance" actually mean, and where each is computed

| Field | Where it comes from | Resets? |
|---|---|---|
| `entitlements.sms_balance` (`/me`, `/billing/entitlements`) | `SmsLedger::currentBalance()` — the running total after every ledger row (sends debit it, top-ups **and** the monthly plan grant credit it) | Never — it's a real wallet, sends/credits accumulate forever |
| `entitlements.ai_questions_remaining` (`/me`, `/billing/entitlements`) | `plan.ai_questions_monthly + this-month's topup_iap sum − this-month's questions asked` | Yes — resets to just the plan allotment on the 1st |
| `entitlements.emails_remaining` (`/me`, `/billing/entitlements`) | `plan.email_monthly − this-month's emails sent` (no top-up product exists for email) | Yes — resets on the 1st, the only lever mid-month is a plan upgrade |
| `usage.sms.balance` / `usage.sms.pct_used` (`GET /usage/summary`) | Same `sms_balance` value, **plus** a separate `pct_used` computed only against `plan_monthly_allotment` — see `usage-api-reference.md`'s "two different numbers" callout | `balance` never resets; `pct_used`'s *denominator* is a monthly figure but the balance itself isn't reset by it |

**Monthly SMS grant, closed this pass (fixed 2026-07-24):** every entitled team's plan SMS allotment is credited to `sms_balance` once per calendar month — immediately at trial start, immediately on any RevenueCat purchase/renewal/product-change/uncancellation event, and via a daily `sms:grant-monthly-credits` reconciliation job as a safety net for anything those two miss (idempotent per team per calendar month either way). Before this pass, `sms_balance` only ever moved via top-ups and sends — a plan's monthly allotment was promised in the UI (`plan_monthly_allotment` in `/usage/summary`) but never actually arrived. Nothing for mobile to change because of this fix — `sms_balance`/`pct_used` were always read from the same fields, they just return correct numbers now.

**80%-quota warning push, added this pass:** once any channel (SMS/AI questions/email) crosses 80% of its monthly allotment, the team owner gets a `quota_warning` push (checked daily, once per channel per calendar month — see `notifications-api-reference.md`'s type table). Tapping it deep-links straight to `UsageDetailScreen` pre-selected on that channel's tab (`usage-flow-screens.md`) — nothing new to build for the tap-navigation itself, the existing type-driven routing already covers it.

---

## 7. Mobile implementation checklist

- [ ] RevenueCat SDK configured, `logIn(userId)` called right after auth, before any purchase/restore.
- [ ] Native paywall/purchase sheet for the 5 subscription products (via RevenueCat Offerings, not a custom pricing table).
- [ ] Post-purchase: poll `GET /me` (or `/billing/entitlements`) up to ~10-15s, don't trust the SDK's local state past that window.
- [ ] Restore Purchases button → SDK restore → `POST /billing/sync`.
- [ ] SMS top-up sheet reading `sms_topup_packs` from `/me` (hide if empty), consumable purchase flow, poll for `sms_balance`.
- [ ] AI top-up sheet reading `ai_topup_packs` from `/me` (hide if empty), consumable purchase flow, poll for `ai_questions_remaining`; "Buy more" entry point from both the low-quota UI state and the Ask AI 422.
- [ ] Subscription status banners for `trial`/`active`/`grace`/`expired` per the table in §3.
- [ ] `quota_warning` push tap → `UsageDetailScreen` pre-selected tab (already covered by the generic type-table routing if `notifications-api-reference.md`'s table is implemented).
- [ ] Never hardcode plan prices or limits client-side — read `entitlements.limits`/top-up catalogs live every time.

---

## See also

- `settings-api-reference.md` — field-by-field `/me`, `/billing/entitlements`, `/billing/sync` reference.
- `settings-flow-screens.md` Screen 4 — `SubscriptionScreen` UI spec.
- `usage-api-reference.md` / `usage-flow-screens.md` — the 80%-warning + usage-history screens this powers.
- `notifications-api-reference.md` — `quota_warning`/`trial_reminder` notification types.
- `ai-api-reference.md` / `ai-flow-screens.md` — the AI question 422/quota UX this top-up flow feeds into.
- `../revenuecat-playstore-setup.md` — what to configure in RevenueCat's dashboard and Google Play Console for all of the above to actually work end-to-end.
