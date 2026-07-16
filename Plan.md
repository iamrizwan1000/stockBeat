# OrderPulse — Product & Technical Specification

**Multi-Channel Order Monitoring, Notifications & Quick Actions — Mobile App for E-commerce Sellers**

| | |
|---|---|
| Working name | OrderPulse (placeholder — rename before launch) |
| Version | 1.0 Draft |
| Date | July 2026 |
| Backend | Laravel (PHP) — REST API |
| Mobile | React Native (iOS + Android) |
| Future | Desktop / web application (Phase 4+) |

---

## 1. Executive Summary

OrderPulse is a mobile-first application for e-commerce sellers who sell on multiple channels — Shopify, WooCommerce, eBay, Etsy, and Amazon. It aggregates orders from all connected stores into a single real-time feed, notifies the seller through push, email, and SMS based on **custom user-defined rules**, and lets them perform routine order actions (fulfill, add tracking, refund, reply to customer) directly from their phone.

**Positioning:** OrderPulse is the seller's *mission control* — not a full order management system (OMS). It deliberately avoids the saturated desktop OMS market (listing sync, inventory sync engines, shipping labels) and instead owns the underserved mobile niche: *see everything, know instantly, act fast.*

**The four jobs of the app:**

1. **See** — unified order feed and revenue dashboard across every connected store.
2. **Know** — rule-based smart notifications via push, email, and SMS (the core differentiator).
3. **Act** — quick actions: fulfill, track, refund, cancel, tag, note — from the phone.
4. **Talk** — unified customer inbox across channels (Phase 2).

**Business model:** Freemium subscription with **four tiers: Free, Starter ($5.99/mo), Pro ($17.99/mo · $172.99/yr), Premium ($44.99/mo · $429.99/yr)** — revised 2026-07-16 from the original Free/Pro-only model after benchmarking against comparable Shopify apps (§16.2/16.3). Billed entirely through **native in-app purchases** (Apple StoreKit / Google Play Billing, managed via RevenueCat) — fully compliant with App Store rules, no external checkout. A **7-day full-featured trial (no card required)** starts at signup — grants Premium specifically, so a trialing seller experiences the complete product — and downgrades gracefully to Free. The paywall trigger is multi-channel itself: the moment a seller connects a second store, they must upgrade — which is exactly the target customer.

---

## 2. Market Analysis & Positioning

### 2.1 What is saturated

Desktop/web multi-channel OMS is crowded: LitCommerce, Sellbrite, M2E, Zoho Inventory, 4Seller, Multiorders, Ordoro and dozens more compete on cross-channel listing sync, inventory sync, and fulfillment. **We do not compete there.**

### 2.2 What is underserved (our gap)

- No mainstream product combines **multi-channel order aggregation + rule-based notifications + quick actions** in a **mobile-first** app.
- Today, a seller on 4 channels juggles 4 separate mobile apps (Shopify, Amazon Seller, eBay, Etsy Seller), each with different notification behavior and no cross-channel rules.
- Existing notification apps (Notify Me, Ordersify, Smart Notifications) are Shopify-only store widgets, not seller-facing multi-channel apps.
- Mobile-first competitors (Flyp, Crosslisting) target casual resellers on Poshmark/Depop — not mainstream Shopify/Woo/marketplace merchants.

### 2.3 Market norms we follow

- Push notifications: generous/unlimited (near-zero cost).
- Email: semi-metered.
- SMS: always metered via credits (real per-message cost, ~$0.01–0.05 via Twilio), with purchasable top-up packs.
- Free tiers gate *sophistication* (custom rules) and *scale* (store count, history), not core visibility.

### 2.4 Target users

- **Primary:** solo sellers and micro-teams (1–5 people) selling on 2+ channels with 10–500 orders/day.
- **Secondary:** single-channel Shopify/Woo sellers (free-tier funnel; they upgrade when they expand to a marketplace).

---

## 3. Product Scope

### 3.1 In scope — mobile app (Phases 1–3)

Monitoring, notifications, rules engine, quick order actions, unified inbox, lightweight analytics, team routing, subscription management.

### 3.2 Out of scope — deferred to desktop app (Phase 4+)

Bulk operations, full listing creation/editing, cross-channel inventory sync engine, CSV import/export, shipping label printing, invoice generation, custom report builder, accounting integrations (Xero/QuickBooks), audit logs. See §12.

### 3.3 Explicitly never in scope

Competing with full OMS platforms; acting as a checkout, payment processor, or storefront.

---

## 4. Feature Specification (Mobile)

### 4.1 Authentication — passwordless email OTP (no passwords anywhere)

Industry-standard flow (Notion/Slack-style). One flow handles both login and signup — the user never needs to know which one they're doing:

```
Screen 1: Email input
  [email field] [Continue →]        + "Continue with Apple" / "Continue with Google"
        │
        ▼  POST /auth/otp/request  (ALWAYS sends OTP — never reveals whether the
        │                           email exists; prevents account enumeration)
Screen 2: OTP entry
  6-digit code boxes · paste-friendly · "Resend" after 30s cooldown
        │
        ▼  POST /auth/otp/verify {email, code}
        │
   ┌────┴──────────────────────┐
   ▼ existing user             ▼ new user (created on verify)
 → straight into the app     → Screen 3: quick profile (ONE screen)
   (feed)                       · Your name  · Business name (optional)
                                · "Where do you sell?" — platform chips
                                  [Shopify][WooCommerce][eBay][Etsy][Amazon]
                                · timezone + currency auto-detected, editable later
                                      │
                                      ▼
                              → Screen 4: connect your first store
                                (chips pre-ordered by their platform answer)
```

**Rules & safeguards:**

- OTP: 6 digits, hashed at rest, 10-minute expiry, single-use, max 5 verify attempts then invalidated.
- Rate limits: 3 OTP requests / 10 min per email, per-IP throttle; resend cooldown 30s.
- Apple/Google one-tap remains as an alternative (Apple requires Sign in with Apple whenever Google sign-in is offered) — all three paths converge on the same user record by verified email.
- Sessions: Sanctum bearer tokens per device (`devices` table), long-lived with refresh; "log out all devices" in settings.
- No passwords = no reset flows, no credential leaks, minimal friction — login is typing your email + a code from your inbox, ~15 seconds.
- Email deliverability is now auth-critical: send OTPs via a dedicated transactional stream (separate from marketing), monitor bounce rates.

### 4.1.1 Store connections

- Connect stores via OAuth in an in-app browser: Shopify, eBay, Etsy, Amazon. WooCommerce via one-click connector plugin (recommended) or REST API keys.
- **Connection UX target: under 60 seconds per store.** One "Add store" button → platform picker → OAuth → done, with a live "syncing your orders…" progress state and first orders appearing in the feed within seconds (fetch most-recent-50 first, backfill in background).
- Multiple stores per platform (e.g., 3 Shopify stores + 2 eBay accounts).
- Connection health screen: token status, last sync, webhook status, plain-language error states with a "Fix it" button (re-auth deep link) — never raw error codes.
- Guided first-run: connect store → enable push → see first order in feed → prompt to create first rule.

### 4.2 Unified order feed (the dashboard)

- Single reverse-chronological feed of orders from **all** connected stores, updated in real time (webhooks where supported, polling elsewhere).
- Each card: channel icon, store name, order number, customer name, item count, total (converted to base currency), fulfillment status, payment status, time.
- Filters: channel, store, status (new / unfulfilled / shipped / refunded / cancelled), date range, value range, tags.
- **Global order search** across all stores by order number, customer name, email, product, or SKU.
- Pull-to-refresh; infinite scroll limited by plan history depth.
- **Ship-by deadline indicators**: orders approaching platform SLA (eBay/Etsy/Amazon handling time) flagged with countdown.
- Snooze / remind-me-later on any order.

### 4.3 Order detail & quick actions

- Full detail: line items with images, quantities, SKUs; customer info; shipping address (tap to copy / open in maps); payment method & status; channel fees where available; order timeline/history.
- Actions (per-platform availability in §7):
  - Mark fulfilled / partially fulfilled + add tracking number & carrier
  - Issue full or partial refund (with confirmation + optional reason)
  - Cancel order
  - Add internal notes and tags (stored in OrderPulse, synced to platform where supported)
  - Message the customer (opens inbox thread)
  - Share packing slip as PDF (generated server-side, shared via native share sheet)
- Actions are queued server-side with optimistic UI + failure rollback and retry.

### 4.4 Notification rules engine ⭐ (core differentiator)

Users compose rules: **WHEN trigger + IF conditions + THEN actions.**

**Triggers:**

| Trigger | Source |
|---|---|
| New order | webhook/poll |
| High-value order | derived |
| Order unfulfilled after X hours | scheduler |
| Ship-by deadline approaching (X hours before SLA) | scheduler |
| Refund requested / dispute or case opened | webhook/poll |
| Order cancelled | webhook/poll |
| Payment failed / pending too long | webhook/poll |
| Negative review / low feedback received | poll |
| Low stock (below threshold) | poll/webhook |
| Order spike (X orders within Y minutes) | derived |
| Refund spike | derived |
| Daily / weekly digest (scheduled summary) | scheduler |

**Conditions (AND/OR groups):** channel, store, order total (>, <, between), product/SKU contains, quantity, customer country, customer is repeat buyer, shipping method, tag.

**Actions:** push notification (with custom sound option — the "cha-ching"), email, SMS (deducts credits), notify specific team member(s), auto-tag the order.

**Controls:** per-rule quiet hours with timezone, cooldown/de-duplication window, per-channel mute, test-fire button ("send me a sample now"), rule enable/disable toggle, execution log (last 50 firings per rule).

**Free tier:** preset alerts only (new order push, daily digest). **Pro:** unlimited custom rules (see §5).

### 4.5 Unified customer inbox (Phase 2)

- Single threaded inbox across channels: eBay messages (full API), Etsy conversations (API, requires app approval), Amazon buyer messages (restricted, template-compliant), Shopify/Woo via order-linked email (send via merchant's connected email or our sending domain with reply-to).
- Message linked to its order — order context panel inside the thread.
- Saved reply templates with variables ({customer_name}, {order_number}, {tracking}).
- Assign conversation to a team member; unread/assigned filters.
- Push notification on new buyer message (rule-controllable).
- Compliance guardrails: per-platform character/content restrictions enforced before send (especially Amazon).

### 4.6 Business overview & analytics-lite

- Today / 7 days / 30 days: revenue, order count, average order value — total and per channel.
- Channel comparison ("Etsy up 30% this week").
- Top products by units/revenue.
- Goal tracking ("82% to your best month").
- Multi-currency: per-store currency + consolidated base-currency view (daily FX rates).
- Morning digest notification: "Yesterday: 23 orders, $1,840. Best seller: X."
- **Home-screen widget** (iOS + Android): today's revenue & order count across all stores.

### 4.7 Team & roles (Pro)

- Invite members by email; roles: Owner, Manager (all actions), Agent (view + inbox only), Viewer.
- Per-member notification routing (rules can target specific members).
- Member-level store visibility restrictions.

### 4.8 Settings & account

- Notification preferences per channel type; global quiet hours; sound selection.
- SMS credit balance + top-up purchase (consumable in-app purchase).
- Subscription management: native IAP upgrade/downgrade, restore purchases, links to App Store / Play Store subscription settings.
- Data export request; account deletion (GDPR).
- Dark mode; language (English first; i18n-ready).

### 4.9 In-app support — live chat ⭐

Every user (including Free) gets a "Help" entry in Settings opening a **live support chat**:

- Real-time chat thread between user and our support staff. User messages arrive in the **admin panel Support Inbox** (§8.7.6); staff reply from there.
- Delivery: WebSocket (Reverb) when the user is in-app; **push notification + full reply by email** when they're not — the user can answer either in-app or by replying to the email (inbound email threads back into the same conversation via plus-addressing, same mechanism as §7.7).
- Thread context automatically attached for staff: plan, connected stores, last sync errors, device/app version — no "what phone do you have?" back-and-forth.
- Canned replies + saved answers; attach screenshots (user → us).
- Status states: open → awaiting-user → resolved; auto-nudge and auto-close after 7 days idle.
- Optional day-1 shortcut: this can be delivered instantly by embedding **Crisp or Intercom** SDKs instead; the in-house spec above uses infrastructure we already have (Reverb + SES inbound) and keeps data first-party. Decision: build in-house in Phase 1 (it's ~90% shared with the customer-inbox code we need anyway).

### 4.10 Design & UX principles

- **Clean, modern, calm.** Single accent color, generous whitespace, large touch targets, native platform conventions (iOS/Android). Dark mode from day one.
- **Zero-training-needed navigation:** 4 tabs — Feed · Rules · Inbox · More (Analytics lives on the Feed header as today's numbers).
- **Speed as a feature:** skeleton loaders, optimistic updates on actions, feed opens in <1s from cold start (cached last state), 60fps lists.
- **Connection flow is the make-or-break moment** — target <60s per store (§4.1.1), always show progress, never dead-end on errors (every error state has a next step).
- **Notifications are respectful by default:** bundling ("12 new orders" instead of 12 pings), quiet hours prominent in onboarding, one-tap mute from any notification.
- **Empty states teach:** empty feed shows "Connect a store", empty rules shows 3 one-tap template rules ("Notify me for orders over $100").
- Accessibility: dynamic type support, VoiceOver/TalkBack labels, WCAG AA contrast.

### 4.11 Conversion mechanics (free → paid)

- Free = 1 store. Connecting a **second store** hits the paywall — the highest-intent moment.
- Locked-teaser notifications: free users receive "High-value order 🔒 — upgrade for instant details & custom alerts."
- History cutoff at 7 days with visible "unlock older orders" affordance.
- **7-day full-featured trial on signup, no card required** (app-level trial, see §6.3); downgrade to Free after with a graceful lock flow (§6.4).
- All purchases are **native in-app purchases** — one-tap upgrade sheet, yearly plan pre-selected with "Save 33%" badge.
- Post-trial win-back: day 3 and day 10 push/email with a one-time intro offer (e.g., first month $4.99 via IAP promotional offer).

---

## 5. Subscription Tiers & Pricing

**Four tiers: Free, Starter, Pro, Premium** (revised 2026-07-16 from the original Free/Pro-only model, after benchmarking against Ordersify, Notify!, Smart Notifications, and Listing Mirror — see §16.2/16.3). Pro and Premium each offer a monthly and yearly option (yearly saves 20%); Starter is monthly-only, positioned as the solo-seller entry tier.

| Feature | Free — $0 | Starter — $5.99/mo | Pro — $17.99/mo · $172.99/yr (save 20%) | Premium — $44.99/mo · $429.99/yr (save 20%) |
|---|---|---|---|---|
| Connected stores | 1 | 3 | 10 | Unlimited |
| Unified feed & search | ✅ | ✅ | ✅ | ✅ |
| Order history | 7 days | 30 days | 1 year | Unlimited |
| Custom rules | — | 5 | Unlimited | Unlimited |
| Rule triggers | Presets only¹ | Core triggers² | Core triggers² | Core + **advanced triggers**³ |
| Push notifications | Presets only | Custom rules | Custom sound per rule | Custom sound per rule |
| Email alerts /mo | 25 | 250 | 1,000 | 5,000 |
| SMS credits /mo | — | 20 | 100 (top-ups available) | 500 (top-ups available) |
| Quick actions | ✅ | ✅ | ✅ | ✅ |
| Unified inbox (Phase 2) | — | — | ✅ | ✅ |
| Team seats | 1 | 1 | 3 | 10 |
| Analytics | Today only | Today + 7d | Full | Full + multi-currency⁴ |
| Widgets & digests | — | Daily digest only | ✅ | ✅ |
| Support | Community | Community | Priority email | Priority email + phone/chat |

¹ new-order push + daily digest — the same free-tier baseline as before.
² new order, high-value, unfulfilled-after-X, ship-by-deadline, order cancelled, refund requested, payment failed, low stock, negative review, digest.
³ order spike, refund spike only — deliberately not `low_stock`/`negative_review`, which read as basic seller hygiene rather than a Premium-only perk (`plan_limits.advanced_triggers_enabled`, `Rule::advancedTriggers()`).
⁴ once the `fx_rates` table exists (§9/§17.3 — still not built).

- **SMS top-up packs (consumable IAP):** 100 credits / $2.99 · 500 credits / $9.99. Non-expiring. Priced above carrier cost (~$0.01–0.05/msg via Twilio) — verify per launch region.
- SMS monthly allotment resets each billing cycle; unused monthly credits do not roll over (top-up credits do).
- Enforcement is server-side, per-Action (`CreateRuleAction`/`UpdateRuleAction`/`ConnectStoreAction`/etc. each check `ResolveEntitlementsAction`'s limits directly) rather than a single centralized plan-gate middleware; the client reads entitlements from the `/me` payload — never trust client-side flags.
- Prices are IAP list prices; Apple/Google commission (15% under $1M/yr via the Small Business / 15% programs) is already absorbed in these numbers.
- **All numeric limits in this table (stores, rules, SMS allotment, history days, seats, trial days, advanced-trigger access) are NOT hardcoded** — they live in the `plans` / `plan_limits` database tables and are editable live from the admin panel (§8.7). Changing a limit takes effect on the next entitlement refresh, no app release required. Only the IAP *prices* and *product IDs* themselves must be changed in App Store Connect / Play Console (store-controlled) — `starter_monthly`/`pro_monthly`/`pro_yearly`/`premium_monthly`/`premium_yearly` are RevenueCat's product identifiers, mapped to a plan tier in `ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS`.
- The 7-day free trial (§6.3) grants **Premium**, not just "Pro" — "full-featured trial" is taken literally so a trialing seller experiences the complete product, advanced triggers included, before choosing which paid tier actually fits.

### 5.1 Customer-facing plan presentation (paywall & store listing copy)

Written Notify Me-style: numeric quotas on everything, benefits not features (see §16.2 for the reference model).

**Free**

- 1 connected store (any platform)
- All your orders in one live feed
- New-order push alerts + daily summary
- 25 email alerts /month
- Quick actions: fulfill, track, refund from your phone
- Last 7 days of orders

**Starter — $5.99/month**

- Up to 3 connected stores
- 5 custom alert rules (new order, high-value, unfulfilled, ship-by-deadline, cancelled, refunded, payment failed, low stock, negative review, digest)
- 20 SMS + 250 email alerts /month
- Today + 7-day analytics
- Last 30 days of orders

**Pro — $17.99/month** · *first month intro offer*

- Up to 10 connected stores across Shopify, WooCommerce, eBay, Etsy & Amazon
- Unlimited custom alert rules
- 100 SMS + 1,000 email alerts /month (top-ups available in-app)
- Custom notification sounds per rule
- Unified customer inbox — reply to any marketplace from one screen
- 3 team members with per-person alert routing
- Full analytics + home-screen widget + 1 year of history
- 7-day free trial, no card required

**Pro Yearly — $172.99/year** *(save 20%)*

- Everything in Pro, one payment

**Premium — $44.99/month**

- Unlimited connected stores
- Everything in Pro, plus **order spike & refund spike alerts** — know the moment volume looks abnormal, not just order-by-order
- 500 SMS + 5,000 email alerts /month
- 10 team members
- Full analytics + multi-currency consolidation, unlimited history
- Priority email + phone/chat support

**Premium Yearly — $429.99/year** *(save 20%)*

- Everything in Premium, one payment

Notes: introductory-offer pricing is a StoreKit/Play introductory offer on the relevant monthly product. Email alerts now carry a numeric quota at every paid tier (admin-tunable) so every channel has a visible number — quota consumption shown in Settings with an upsell at 80%.

---

## 6. Billing Strategy — In-App Purchases (all platforms)

All subscriptions and SMS top-ups are sold as **native in-app purchases**: Apple StoreKit 2 on iOS, Google Play Billing on Android. This is the App Store–safest approach — no external checkout, no review risk.

### 6.1 Implementation

- **RevenueCat** as the cross-platform IAP layer (SDK in React Native; server webhooks to Laravel). It handles receipt validation, renewal tracking, plan changes, refunds, and cross-device restore — building this raw against StoreKit + Play Billing is error-prone.
- **Products (revised 2026-07-16 for the 4-tier model, §5):** `starter_monthly` ($5.99), `pro_monthly` ($17.99)/`pro_yearly` ($172.99), `premium_monthly` ($44.99)/`premium_yearly` ($429.99), consumables `sms_100` ($2.99), `sms_500` ($9.99). Mapped to a `Plan::key` in `ProcessRevenueCatEventAction::SUBSCRIPTION_PLAN_PRODUCTS` — built 2026-07-16, real end-to-end (webhook auth, idempotency via `revenuecat_events`, subscription state machine).
- **Entitlement flow:** purchase in app → store validates → RevenueCat webhook (`INITIAL_PURCHASE`, `RENEWAL`, `CANCELLATION`, `BILLING_ISSUE`, `EXPIRATION`, `PRODUCT_CHANGE`) → Laravel `/hooks/revenuecat` updates the `subscriptions` table (`status` + `plan_key`) → entitlements served via `/me` (`Subscription::effectivePlanKey()`). The backend is the single source of truth; the app also checks RevenueCat SDK locally for instant unlock, then reconciles with the server.
- **Restore purchases** button (App Store requirement); anonymous→authenticated identity linking via RevenueCat `app_user_id = user_id`.
- **Billing issues:** store enters grace period (16 days Apple / configurable Google) — keep the current tier active during grace with an in-app "payment issue" banner; downgrade to Free on `EXPIRATION`.
- **Refunds/chargebacks:** handled by the stores; RevenueCat webhook revokes entitlement.
- **Commission economics:** Apple Small Business Program + Google Play 15% tier → effective commission 15% under $1M/yr. Net on $17.99 (Pro) ≈ $15.29; net on $172.99/yr ≈ $147.04. Enroll in both programs before launch.

### 6.2 Web/desktop billing (future)

When the desktop app ships (Phase 4), add Stripe for web-originated subscriptions. The entitlement service already abstracts the payment source (`provider: apple|google|stripe`), so this is additive, not a rework.

### 6.3 Free trial — 7 days, no card required

How comparable apps do it: most Shopify-ecosystem apps offer 7-day trials (e.g., Smart Notifications: 7 days), some 14; consumer subscription apps typically use StoreKit intro-offer trials (card on file, auto-converts — higher conversion but sign-up friction and "forgot to cancel" refunds). **We use an app-level trial instead:**

- On account creation, backend grants `trial` entitlement = full Pro for 7 days (`trial_ends_at` on the user). No payment info collected — maximizes activation.
- Trial state is server-controlled and device-independent (no reinstall abuse: keyed to account + store-connection fingerprints).
- Countdown surfaced in-app from day 4 ("3 days of Pro left"); push + email on day 5 and day 7.
- One trial per account, ever. Optionally pair with a StoreKit **introductory offer** (first month $4.99) as the day-10 win-back for expired trials.

### 6.4 Trial-end & downgrade behavior (exact rules)

What happens the moment `trial_ends_at` passes (same logic applies to a lapsed Pro subscription):

| Area | Behavior after downgrade to Free |
|---|---|
| Stores | First-connected store stays active; **extra stores are paused, not deleted** — visible greyed-out with "Reactivate with Pro". No data loss |
| Order data | Nothing is deleted. Display window truncates to last 7 days; older orders unlock instantly on re-upgrade |
| Custom rules | Disabled (kept, greyed out) — presets (new-order push, daily digest) keep working |
| SMS/email alerts | Stop; unused top-up SMS credits are retained (frozen) until re-upgrade |
| Inbox | Read-only: can read existing threads, cannot send |
| Team seats | Extra members lose access (memberships kept, suspended) |
| Analytics | Reverts to today-only |
| Syncing | Backend keeps syncing the active store fully; paused stores stop syncing (resume + backfill on upgrade) |
| UX | One-time "trial ended" screen with plan comparison; small persistent upgrade banner; **no nagging modals** on every open |

Design principle: **downgrade freezes, never destroys.** Everything the user built (rules, stores, history, threads) is preserved and springs back on upgrade — this makes re-subscribing feel like flipping a switch, which is the point.

---

## 7. Platform Integration Guides

Each platform is implemented as an **adapter** behind a common interface (see §8.3). This section gives an AI/developer everything needed to start each integration.

### 7.1 Shopify

| | |
|---|---|
| API | GraphQL Admin API (REST Admin API is legacy) |
| Auth | OAuth 2.0 (authorization code). Public app via Partner account (free) |
| Real-time | **Webhooks** — best-in-class |
| Rate limits | GraphQL calculated cost (1,000 points/min standard) |
| Cost to us | $0 if distributed outside the Shopify App Store (recommended — lets us bill via Stripe). App Store listing would require $19 one-time fee + Shopify Billing API + rev share above $1M lifetime |

- **Scopes:** `read_orders`, `write_orders`, `read_fulfillments`, `write_fulfillments`, `read_products`, `read_inventory`, `write_inventory`, `read_customers`.
- **Webhooks to register:** `orders/create`, `orders/updated`, `orders/cancelled`, `orders/fulfilled`, `refunds/create`, `inventory_levels/update`, `app/uninstalled`. Verify HMAC-SHA256 header on every delivery.
- **Capabilities:** full — view orders, fulfill + tracking, refunds (full/partial), cancel, edit tags/notes, inventory read/update, product quick-edit.
- **Messaging:** no native customer-chat API → inbox uses order email (see §7.7).
- **Gotchas:** webhook deliveries can drop — run reconciliation polling every 10–15 min as safety net; respect `X-Shopify-Shop-Domain` for multi-store; handle mandatory GDPR webhooks (`customers/data_request`, `customers/redact`, `shop/redact`).

### 7.2 WooCommerce

| | |
|---|---|
| API | WooCommerce REST API v3 (over WordPress REST) |
| Auth | Consumer key/secret (HTTPS basic) or our free connector plugin (recommended UX) |
| Real-time | WooCommerce webhooks (configurable per topic) |
| Rate limits | None official — the merchant's server is the limit; be gentle |
| Cost to us | $0. Optional connector plugin hosted free on WordPress.org |

- **Connector plugin (recommended):** small WordPress plugin that registers webhooks automatically, adds a one-click connect (key exchange), and improves reliability vs. manual key entry.
- **Webhooks:** `order.created`, `order.updated`, `order.deleted`, `product.updated` (stock). Signature = HMAC-SHA256 with shared secret.
- **Capabilities:** full — orders CRUD, status changes (processing/completed/refunded/cancelled), refunds via API, notes, inventory and product updates.
- **Gotchas:** enormous variance in merchant hosting quality — webhooks fail often; poll as fallback with per-store adaptive intervals. Some sites block REST API via security plugins; detect and surface clear troubleshooting in connection health screen. Timezone/date formats vary by WP config.

### 7.3 eBay

| | |
|---|---|
| API | Sell APIs: Fulfillment API (orders), Inventory API, Post-Order API (returns/cancellations) |
| Auth | OAuth 2.0 (user token, refresh up to 18 months) |
| Real-time | Platform Notifications / Notification API (push to our endpoint) |
| Rate limits | Per-API daily call limits (default ~5,000/day/API; can request increase) |
| Cost to us | $0 — developer program and keys are free |

- **Key endpoints:** `getOrders` (Fulfillment API), `createShippingFulfillment` (tracking upload), Post-Order API for cancellations/returns/refunds/cases.
- **Messaging:** Trading API member messages — **best messaging API of the five**; full buyer-seller conversation support → flagship inbox channel.
- **Feedback:** poll feedback via Trading API for negative-feedback alerts.
- **Ship-by:** orders carry handling-time SLA → drives deadline alerts.
- **Gotchas:** mixed REST + legacy XML (Trading API) — isolate legacy calls inside the adapter; token refresh must be rock-solid (18-month refresh tokens still expire); marketplace-specific sites (ebay.com, .co.uk, .de) per account.

### 7.4 Etsy

| | |
|---|---|
| API | Etsy Open API v3 (REST) |
| Auth | OAuth 2.0 + PKCE |
| Real-time | **No webhooks** — polling only |
| Rate limits | 10,000 requests/day, 10/sec per app (default) |
| Cost to us | $0 fee, but **app approval review required** for full/commercial access |

- **Key endpoints:** `getShopReceipts` (orders), `createReceiptShipment` (tracking), `updateShopReceipt`, Ledger/Payments endpoints for refunds, conversations endpoints for messaging (approval-gated).
- **Capabilities:** view orders, fulfill + tracking, refunds, messaging (post-approval). Cancellations are seller-initiated request flows, more limited.
- **Polling strategy:** receipts poll every 60–120s per active store (adaptive: faster during merchant's business hours), using `min_created`/`min_last_modified` cursors. Budget within 10k/day: ~1 poll/90s ≈ 960 calls/day/store — plan per-store budgets and request limit increase as we scale.
- **Gotchas:** approval process takes time — apply early with clear use-case description; reviews API is read-only (fine for alerts); API terms restrict off-platform marketing in messages.

### 7.5 Amazon

| | |
|---|---|
| API | Selling Partner API (SP-API) |
| Auth | OAuth via Seller Central app authorization + LWA (Login with Amazon) tokens + AWS SigV4 |
| Real-time | Notifications API → **Amazon SQS/EventBridge** (e.g., `ORDER_CHANGE`) + polling |
| Rate limits | Per-endpoint token-bucket (e.g., getOrders ~0.0167 rps burst 20) — strict |
| Cost to us | $0 developer registration currently (Amazon floated a $1,400/yr fee for 2026 then reversed it — re-verify at build time). Selling Partner Appstore listing free |

- **Registration:** public developer profile requires vetting (data protection policy, security questionnaire) — **takes weeks; start earliest of all platforms** even though it ships last.
- **Key operations:** Orders API `getOrders`/`getOrderItems` (PII requires Restricted Data Token via RDT), Feeds/Shipping confirmation for tracking upload, Notifications API subscriptions into SQS.
- **Capabilities:** view orders (slightly delayed), confirm shipment + tracking, limited refunds (via Feeds), **very restricted messaging** — only template-based, order-related messages through the Messaging API; enforce templates in inbox composer.
- **Gotchas:** strictest data policy (PII handling, encryption-at-rest attestations); order data can lag minutes; multi-marketplace (NA/EU/FE regions = separate endpoints); throttling demands a token-bucket-aware queue per seller.

### 7.6 TikTok Shop (Phase 3+ candidate)

Open Partner API with OAuth, order list/detail, fulfillment, and webhooks. Fast-growing, near-zero competition among notification apps — strong "first mover" channel after the core five. Design the adapter interface so this drops in without core changes.

### 7.7 Inbox via email (Shopify & WooCommerce)

Since Shopify/Woo lack chat APIs: outbound messages send from `orders@<ourdomain>` (or merchant's verified sending address) with `Reply-To` threading; inbound replies received via SES/Postmark inbound webhook, matched to thread by plus-addressing token (`orders+{thread_id}@`). Fallback: mailto handoff in v1, full email threading in Phase 2.

### 7.8 Platform capability matrix (summary)

| Capability | Shopify | Woo | eBay | Etsy | Amazon |
|---|---|---|---|---|---|
| Real-time orders | ✅ webhooks | ✅ webhooks* | ✅ notifications | ⚠️ polling | ⚠️ SQS + delay |
| Fulfill + tracking | ✅ | ✅ | ✅ | ✅ | ✅ |
| Refunds | ✅ | ✅ | ✅ | ✅ | ⚠️ limited |
| Cancel | ✅ | ✅ | ✅ | ⚠️ request flow | ⚠️ limited |
| Messaging | ⚠️ email | ⚠️ email | ✅ full | ✅ approval-gated | ⚠️ templates only |
| Inventory update | ✅ | ✅ | ✅ | ✅ | ✅ |
| Reviews/feedback alerts | ✅ | ✅ | ✅ | ⚠️ read-only | ✅ |

\* unreliable hosting → polling fallback required.

---

## 8. Technical Architecture

### 8.1 High-level

```
 [Shopify]  [Woo]  [eBay]  [Etsy]  [Amazon SQS]
     │        │       │       │         │
     ▼        ▼       ▼       ▼         ▼
 ┌───────────────────────────────────────────┐
 │  Ingestion layer (webhooks + pollers)     │
 │  Laravel: webhook controllers, scheduled  │
 │  pollers, per-platform Adapters           │
 └───────────────┬───────────────────────────┘
                 ▼ (queue: Redis)
 ┌───────────────────────────────────────────┐
 │  Normalizer → unified Order model → DB    │
 │  Event: OrderReceived / OrderUpdated ...  │
 └───────────────┬───────────────────────────┘
                 ▼
 ┌───────────────────────────────────────────┐
 │  Rules Engine (evaluates rules per event) │
 └───────┬───────────┬───────────┬───────────┘
         ▼           ▼           ▼
   Push (FCM)    Email (SES)  SMS (Twilio)
         │
         ▼
 ┌───────────────────────────────────────────┐
 │  REST API (Sanctum) + WebSockets (Reverb) │
 │            React Native app               │
 └───────────────────────────────────────────┘
```

### 8.2 Backend (Laravel)

- **Framework:** Laravel 12, PHP 8.3+ — **one project** serving the REST APIs and the Inertia.js + React admin panel. Modules organized by domain: `Connections`, `Orders`, `Rules`, `Notifications`, `Inbox`, `Billing`, `Analytics`, `Team`, `Support`, `Admin`. All business logic in `app/Actions/*` (shared by API controllers and Inertia controllers — see §8.7).
- **Auth:** Laravel Sanctum token auth for the mobile API; social sign-in via Socialite (Apple/Google).
- **Database:** MySQL 8 (or PostgreSQL). Redis for cache, queues, rate-limit buckets.
- **Queues:** Laravel Horizon. Dedicated queues: `ingest` (webhook processing), `poll`, `rules`, `notify-push`, `notify-email`, `notify-sms`, `actions` (outbound platform calls), `billing`. Per-store throttling middleware on `actions` and `poll` (critical for Amazon/Etsy).
- **Scheduler:** `schedule:work` — pollers (adaptive intervals), time-based rule triggers (unfulfilled after X, ship-by deadlines), digests, FX rate refresh, token refresh sweeps, reconciliation jobs.
- **Real-time to app:** Laravel Reverb (WebSockets) for live feed updates while the app is open; push notifications when backgrounded.
- **Push:** FCM (Android + iOS via APNs through FCM). Store device tokens per user/device.
- **Email:** Amazon SES (or Postmark) + inbound parsing for the email-based inbox.
- **SMS:** Twilio (Messaging Service, sender pools per region); every send debits the `sms_ledger`.
- **Secrets:** platform tokens encrypted at rest (Laravel encrypted casts / KMS envelope encryption — required for Amazon compliance).
- **Observability:** structured logs, Sentry, Horizon metrics, per-adapter sync-health dashboards (drives the in-app connection health screen).

### 8.3 Channel Adapter pattern (key abstraction)

Every platform implements:

```php
interface ChannelAdapter {
    public function connect(ConnectRequest $r): StoreConnection;   // OAuth/keys
    public function refreshAuth(StoreConnection $c): void;
    public function fetchOrders(StoreConnection $c, Cursor $since): OrderBatch;
    public function registerWebhooks(StoreConnection $c): void;    // no-op for Etsy
    public function parseWebhook(Request $r): ?PlatformEvent;      // + signature verify
    public function fulfill(Order $o, FulfillmentData $d): ActionResult;
    public function refund(Order $o, RefundData $d): ActionResult;
    public function cancel(Order $o, ?string $reason): ActionResult;
    public function sendMessage(Thread $t, OutboundMessage $m): ActionResult;
    public function capabilities(): CapabilitySet;                 // drives UI buttons
}
```

`capabilities()` powers the UI: buttons render only for actions the platform supports — the app stays unified while honoring per-platform limits. New channels (TikTok Shop) = new adapter, zero core changes.

### 8.4 Rules engine design

- Rules stored as JSON: `{trigger, conditions: {all: [...], any: [...]}, actions: [...], controls: {quiet_hours, cooldown_minutes}}`.
- Order events dispatch `RuleEvaluationJob` → loads user's active rules for that trigger → evaluates condition tree against the normalized order → enqueues one job per action channel.
- Time-based triggers (unfulfilled-after-X, ship-by): scheduler scans orders with due `check_at` timestamps (indexed column set at ingest).
- Derived triggers (spikes): sliding-window counters in Redis per store.
- De-duplication: `rule_executions` unique key (rule_id, order_id, trigger) + cooldown window.
- Every firing logged in `rule_executions` (feeds the in-app execution log).

### 8.5 Mobile app (React Native)

- **Stack:** React Native (Expo, dev-client workflow), TypeScript, React Navigation, TanStack Query (server cache), Zustand (local state), `react-native-mmkv` (storage).
- **Push:** Expo Notifications / FCM; deep links from notification → order detail / thread.
- **Live updates:** WebSocket (Reverb) subscription on feed screen; silent push for background badge updates.
- **Widgets:** iOS WidgetKit + Android Glance via native modules (Expo config plugins) — today's revenue/orders.
- **Offline:** cached feed readable offline; actions disabled offline with clear state.
- **Screens (v1):** Onboarding/Connect, Feed, Order Detail, Rules list + Rule builder, Notifications center, Analytics, Settings, Paywall. Phase 2 adds Inbox + Thread.

### 8.6 Security & compliance

- All tokens encrypted at rest; TLS everywhere; webhook signature verification on every platform.
- PII minimization: store only order-required customer data; honor Shopify GDPR webhooks; data export + deletion flows.
- Amazon data protection policy compliance (restricted data via RDT, encryption, retention limits ≤30 days for PII where mandated).
- Per-user rate limiting on API; audit trail on destructive actions (refund/cancel).

### 8.7 Admin Panel — full specification (internal — Inertia.js + React)

Web admin at `admin.<domain>` (local dev: `/admin` path prefix), built in the **same Laravel project using Inertia.js + React** (Laravel's official React starter kit). One deploy, one repo, no CORS — while the UI is real React components (skill shared with the React Native app). **Decision (2026-07-13): admin UI is built with Shopify Polaris** (`@shopify/polaris`) rather than a custom design system.

**Architecture rule (critical):** all business logic lives in `app/Actions/*` classes — the single source of truth consumed by three thin layers: `/api/v1/*` controllers (mobile JSON), `/admin/api/v1/*` controllers (admin JSON), and Inertia admin page controllers. Admin-managed data (plan limits, promotions, broadcasts, config) therefore flows to the mobile app automatically through the same Actions + database — edit a plan limit in admin, mobile's next `/me` reflects it. (Filament remains a fallback if admin build speed ever matters more than custom design.)

Separate admin auth guard + mandatory 2FA. Admin roles: **superadmin** (everything), **support** (customers, support inbox, comps within limits), **readonly** (dashboards only). Every write action lands in `admin_audit_log`.

#### 8.7.1 Dashboard (KPIs — see at a glance)

Signups (today/7d/30d + trend), DAU/MAU, active trials + trials expiring this week, trial→paid conversion %, paying subscribers (monthly vs yearly split), **MRR / ARR**, churn % and cancellations this month, ARPU, top platforms connected, notification volume (push/email/SMS), SMS cost vs SMS revenue, support inbox open count + median first-response time. Funnel widget: signup → store connected → push enabled → rule created → paywall seen → paid.

#### 8.7.2 Customers (see everything, act on anything)

- **List:** search by email/name/store; filters: plan (free/trial/active/grace/expired/cancelled), platform connected, signup date, last-active, country, LTV; export CSV.
- **Detail page per customer:** profile + devices/app versions · connected stores with live sync health · subscription timeline (every RevenueCat event) · payments/LTV · SMS ledger · rules list + notification volume · support-chat history · funnel position · flags (trial-abuse suspect, high SMS cost).
- **Actions:** extend trial (n days) · grant complimentary Pro (with expiry) · grant bonus SMS credits · pause/suspend account · force logout · resend OTP-blocked unlock · GDPR data export · account delete · open their support thread.

#### 8.7.3 Plans & pricing (fully editable, live)

- Edit every `plan_limits` value: stores, rules, SMS/email quotas, history days, seats, trial length, feature toggles (inbox, analytics level, widgets) — versioned, effective on next entitlement refresh, no app release.
- Paywall copy blocks (§5.1 bullets) editable as remote content.
- SMS top-up pack definitions.
- Feature flags with % rollout and plan scoping.
- Reminder shown in UI: IAP *prices* change in App Store Connect / Play Console.

#### 8.7.4 Promotions & discounts

- **Offer-code campaigns:** create/track batches of Apple **Offer Codes** and Google Play promo codes (e.g., "LAUNCH50 — 50% off 3 months", influencer codes, win-back codes). Admin records campaign → generates codes in the store consoles → uploads/links them; redemption stats tracked via RevenueCat events. In-app "Redeem code" screen invokes the native redemption sheet.
- **Introductory offers** management (first-month $3.99, free-trial-to-paid offers) — configured in store consoles, tracked here.
- **Server-side comps** (no store involvement): complimentary Pro days, bonus SMS credits, per-user or per-segment — instant, admin-initiated.
- Campaign performance: redemptions, conversion to full price, revenue impact.

#### 8.7.5 Messaging center (admin → users)

- **Compose to: all users / a segment / a single user.** Segments built from filters (plan, platform connected, inactive 7+ days, trial ending in 2 days, country…), saved and reusable.
- **Channels:** push notification, email, in-app banner/announcement — any combination.
- Templates with variables ({first_name}, {plan}, {trial_days_left}); preview + send-test-to-self; schedule for later (user-local time optional).
- Delivery report per campaign: sent/delivered/opened (push open + email open), unsubscribes.
- Guardrails: marketing emails honor unsubscribe (transactional/OTP exempt); push broadcasts rate-limited; superadmin approval required for all-users sends.

#### 8.7.6 Support inbox (live chat — pairs with §4.9)

- All user chat threads: filters (open / awaiting-user / resolved / unassigned), assignment to staff, priority tags.
- Thread view with full customer context sidebar (plan, stores, sync errors, device) and quick actions (extend trial, comp, bonus credits) inline — resolve issues *in* the conversation.
- Canned replies library; internal notes (invisible to user); collision detection (two agents typing).
- Replies deliver in-app (WebSocket) + push + email fallback automatically; user email replies thread back in.
- SLA dashboard: first-response time, resolution time, threads per agent, CSAT (optional 👍/👎 after resolve).

#### 8.7.7 Operations & health

- Platform health board: webhook failure rates, poller lag, API quota usage (Etsy/Amazon budgets), token-expiry queue, failed-action retry queue.
- Notification delivery monitor: per-channel sends/failures, Twilio spend in real time, per-team cost-vs-revenue guardrail alerts.
- Abuse guards: runaway rule volume, trial-abuse fingerprint matches, SMS anomalies.
- App config: minimum supported app version (force-update prompt), maintenance-mode banner, remote config JSON.

#### 8.7.8 Admin team & audit

- Manage admin users, roles, 2FA reset; full audit log (who/what/before/after/when) with search.

---

## 9. Data Model (core entities)

```
plans (id, key: free|pro, name, active)
plan_limits (plan_id, key: max_stores|max_rules|sms_monthly|history_days|
             team_seats|trial_days|inbox_enabled|analytics_level|widgets_enabled,
             value, updated_by, updated_at)        // admin-editable, versioned
feature_flags (key, enabled, rollout_pct, plan_scope, updated_by)
admin_users (id, name, email, role: superadmin|support|readonly, 2fa_secret)
admin_audit_log (admin_id, action, target_type, target_id, before JSON, after JSON, at)

users (id, name, email UNIQUE, business_name, base_currency, timezone,
       sells_on JSON, marketing_opt_in, last_active_at, ...)   // passwordless — no password column
otp_codes (id, email, code_hash, expires_at, attempts, consumed_at, ip, created_at)
sessions/devices → devices table below (Sanctum tokens per device)

promo_campaigns (id, name, type: offer_code|intro_offer|server_comp, store_ref,
                 config JSON, starts_at, ends_at, created_by, stats JSON)
comp_grants (id, team_id, type: pro_days|sms_credits, amount, expires_at,
             granted_by, reason)
segments (id, name, filters JSON, created_by)
broadcasts (id, segment_id NULL, user_id NULL, channels JSON, title, body,
            template_vars JSON, scheduled_at, sent_at, stats JSON, created_by,
            approved_by)
announcements (id, title, body, audience JSON, starts_at, ends_at, dismissible)

support_threads (id, user_id, status: open|awaiting_user|resolved, assigned_admin_id,
                 priority, last_message_at, csat NULL)
support_messages (thread_id, direction: user|staff|note, admin_id NULL, body,
                  attachments JSON, delivered_via JSON, created_at)
canned_replies (id, title, body, created_by)

app_config (key, value JSON, updated_by)   // min_version, maintenance, remote config
teams (id, owner_id, name)
team_members (team_id, user_id, role: owner|manager|agent|viewer, store_visibility JSON)
subscriptions (id, team_id, provider: apple|google, rc_app_user_id,
               product_id: pro_monthly|pro_yearly, status: trial|active|grace|expired,
               trial_ends_at, expires_at, renewed_at, raw JSON)
sms_ledger (id, team_id, delta, reason: monthly_grant|topup_iap|send|freeze,
            balance_after, meta)

store_connections (id, team_id, platform: shopify|woo|ebay|etsy|amazon,
                   name, credentials ENCRYPTED, status, last_sync_at,
                   webhook_status, region/marketplace, settings JSON)

orders (id, team_id, connection_id, platform, external_id, order_number,
        status, fulfillment_status, payment_status,
        currency, total, total_base_currency,
        customer_name, customer_email, shipping_address JSON,
        placed_at, ship_by_at, check_at [for time triggers],
        tags JSON, raw JSON, UNIQUE(connection_id, external_id))
order_items (order_id, external_id, sku, title, image_url, qty, price)
order_events (order_id, type, payload JSON, occurred_at)   // timeline
order_notes (order_id, user_id, body)

rules (id, team_id, name, trigger, conditions JSON, actions JSON,
       controls JSON, enabled, created_by)
rule_executions (id, rule_id, order_id NULL, trigger, actions_result JSON,
                 fired_at, UNIQUE dedup key)

notifications (id, user_id, type, title, body, data JSON, read_at)
devices (user_id, platform: ios|android, push_token, last_seen_at)

threads (id, team_id, connection_id, order_id NULL, channel,
         external_thread_id, customer_name, assigned_to NULL, last_message_at)
messages (thread_id, direction: in|out, body, sent_by NULL, external_id,
          status: queued|sent|delivered|failed, created_at)
reply_templates (team_id, name, body_with_variables)

daily_stats (team_id, connection_id, date, orders_count, revenue,
             revenue_base, aov, refunds)          // pre-aggregated for analytics
fx_rates (base, quote, rate, date)
```

---

## 10. API Surface (v1 sketch)

**API-first build approach:** the backend exposes two token-authenticated REST namespaces — `/api/v1/*` (mobile app) and `/admin/api/v1/*` (admin panel). Both are built and testable independently (Postman/Pest feature tests) before any UI exists; the React Native app and the Inertia/React admin are pure consumers of the shared `app/Actions/*` layer (§8.7). OpenAPI spec generated from routes (e.g., Scribe) and kept in the repo.

```
POST   /auth/otp/request {email}        → always 200 (no account enumeration)
POST   /auth/otp/verify {email, code}   → token + is_new_user flag
POST   /auth/social {provider, id_token} → token (Apple/Google)
POST   /auth/logout | /auth/logout-all
POST   /profile/setup {name, business_name, sells_on[]}   (new users)
GET    /me                          → profile + entitlements + sms balance
POST   /connections/{platform}/start   → OAuth URL / key intake
GET    /connections                  · DELETE /connections/{id}
GET    /orders?filters…&cursor=      · GET /orders/{id}
POST   /orders/{id}/fulfill | /refund | /cancel | /notes | /tags
GET    /orders/{id}/packing-slip     → PDF URL
GET    /rules · POST /rules · PUT /rules/{id} · POST /rules/{id}/test
GET    /rules/{id}/executions
GET    /notifications · POST /notifications/read
GET    /threads · GET /threads/{id}/messages · POST /threads/{id}/messages
GET    /analytics/summary?range=     · GET /analytics/products
GET    /team · POST /team/invite · PUT /team/{member}
POST   /devices (push token)
GET    /billing/entitlements         · POST /billing/sync (client→server RC reconcile)
GET    /support/thread · POST /support/messages        (live chat, user side)
GET    /config                       → min_version, announcements, remote flags
WS     feed.{team_id} (Reverb channels: order.created, order.updated, message.new,
                       support.reply, entitlements.changed)
Webhooks (inbound): /hooks/shopify/{topic} /hooks/woo /hooks/ebay /hooks/etsy(n/a) /hooks/amazon-sqs(consumer) /hooks/revenuecat /hooks/ses-inbound

Admin namespace /admin/api/v1/* (admin-token + role middleware):
  /dashboard/kpis · /customers (+detail, +actions: extend-trial, comp, credits,
  suspend, gdpr-export, delete) · /plans + /plan-limits (CRUD) · /feature-flags
  /promotions (campaigns, comps) · /segments · /broadcasts (+approve, +stats)
  /announcements · /support/threads (+assign, +reply, +resolve, +canned-replies)
  /ops/health · /ops/notifications · /config · /admins · /audit-log
```

---

## 11. Roadmap

**Phase 1 — MVP (~3–4 months):** Shopify + WooCommerce adapters; unified feed + search; order detail + quick actions (fulfill/tracking, refund, cancel, notes/tags); rules engine v1 (new order, high-value, unfulfilled-after-X, digest) with push + email; SMS with credit ledger + top-up IAP; **IAP billing (RevenueCat) + 7-day trial + trial-end downgrade flow**; analytics-lite (today/7/30); onboarding + connection health. *Launch.*

**Phase 2 (~2–3 months):** eBay + Etsy adapters (Etsy approval filed during Phase 1); unified inbox (eBay + Etsy messaging, email threading for Shopify/Woo); reply templates; team seats + routing; widgets + morning digest; negative-feedback and ship-by-deadline triggers; win-back intro offers.

**Phase 3 (~2–3 months):** Amazon adapter (developer vetting filed at project start); Amazon template-compliant messaging; spike/anomaly triggers; goal tracking; multi-currency consolidation; TikTok Shop evaluation; outbound webhooks/API for Pro.

**Phase 4 — Desktop/web app:** everything in §12, built on the same Laravel API.

**File on day one (long lead times):** Amazon SP-API developer registration, Etsy app approval, Twilio number + regional SMS compliance (e.g., A2P 10DLC in the US), Apple Developer account.

---

## 12. Deferred to Desktop (future scope)

Bulk order operations; full listing/product creation & editing; cross-channel inventory sync engine; CSV import/export; shipping label purchase & printing; invoice generation; custom report builder & scheduled reports; accounting integrations (Xero/QuickBooks); full audit logs; agency/multi-team management. The desktop app consumes the same API — no backend rewrite.

---

## 13. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Amazon/Etsy approval delays | File on day one; launch order Shopify→Woo→eBay→Etsy→Amazon |
| Store commission eats margin | Enroll in Apple Small Business Program + Google 15% tier (15% not 30% under $1M/yr); pricing already absorbs it |
| Trial abuse (repeat free trials) | Server-side one-trial-per-account + store-connection fingerprinting |
| Webhook unreliability (Woo, Shopify) | Reconciliation polling safety net; connection health UI |
| SMS costs/regulatory (10DLC, DLT) | Metered credits, sender registration early, per-region pricing |
| Etsy/Amazon rate limits at scale | Per-store token-bucket queues; request limit increases with traction |
| Amazon fee policy changes (e.g., revived developer fees) | Re-verify at build time; Amazon is Phase 3, decision can wait |
| Platform ToS on customer messaging | Compliance guardrails in inbox composer per channel |
| Notification fatigue → churn | Quiet hours, cooldowns, digest bundling, per-rule mute |

---

## 14. Open Decisions

1. Final product name + domain.
2. Primary launch market (affects SMS compliance work: US 10DLC vs AU/UK sender IDs).
3. MySQL vs PostgreSQL (either fine; pick by team familiarity).
4. Expo managed vs bare workflow for widget/native-module needs (recommend Expo + config plugins).
5. Whether to also list on Shopify App Store later for distribution (accepting Shopify Billing) — revisit after traction.

---

## 15. Development Checklist (build tracker)

Use this as the master build list — tick items as completed. Ordering follows the roadmap (§11).

### 15.1 Foundations

- [ ] Laravel project scaffold (modules: Connections, Orders, Rules, Notifications, Inbox, Billing, Analytics, Team, Support, Admin) — *partial (2026-07-13): base Laravel + Inertia/React project configured (APP_NAME, SQLite dev DB); starter password-auth for end users removed per §4.1; domain modules pending*
- [ ] **API-first:** `/api/v1` + `/admin/api/v1` namespaces, OpenAPI generation, Pest feature tests per endpoint (build & verify APIs before UIs) — *partial (2026-07-15): `/api/v1` namespace live (`routes/api/v1.php`), Pest feature tests per endpoint established as the working convention, unified `{success, message, data|errors}` response envelope (`App\Http\Responses\ApiResponse` + exception-handler rendering in `bootstrap/app.php`) applied to every endpoint; `/admin/api/v1` and OpenAPI generation pending*
- [ ] Passwordless auth: OTP request/verify endpoints, rate limits, Apple/Google social convergence, device sessions — *partial (2026-07-15): OTP request/verify built (`app/Actions/Auth`), 30s resend cooldown, 10-min expiry, 5-attempt lockout, no-enumeration on request, named rate limiters (`otp-request`, `otp-verify`), Sanctum bearer tokens issued on verify, `/profile/setup` completes new-user onboarding (name/business_name/sells_on/timezone/base_currency) and creates the user's owning `Team` + owner `TeamMember`; `devices` table exists but device registration endpoint not built; Apple/Google social convergence pending*
- [ ] MySQL schema + migrations for all §9 entities — *partial (2026-07-16): `users` reworked passwordless, `otp_codes`, `devices`, `personal_access_tokens`, `teams`, `team_members`, `team_invites` (added for §4.7, not in the original §9 listing), `plans`, `plan_limits`, `subscriptions`, `store_connections`, `orders`, `order_items`, `order_events`, `order_notes`, `rules`, `rule_executions`, `notifications`, `sms_ledger`, `admin_audit_log` in place (Free/Pro seeded via `PlanSeeder`)*
- [ ] Redis + Horizon queues (`ingest`, `poll`, `rules`, `notify-*`, `actions`, `billing`) with per-store throttling middleware — not started; everything (`notify-email`, `RuleEvaluationJob`, `PollWooOrdersJob`) rides the default `database` queue driver on one unnamed queue as a placeholder, with no per-store throttling yet
- [ ] Sanctum auth + Apple/Google social sign-in — *partial (2026-07-15): Sanctum token auth done (mobile API); Apple/Google social sign-in pending*
- [x] Encrypted credential storage for platform tokens — *(2026-07-15): `store_connections.credentials` uses Laravel's `encrypted:array` cast; verified ciphertext at rest in `tests/Feature/Connections/ConnectionsTest.php`*
- [ ] React Native (Expo + TypeScript) scaffold; navigation, TanStack Query, Zustand, MMKV
- [ ] CI, staging environment, Sentry, structured logging

### 15.2 Accounts to open on day one (long lead times)

- [ ] Apple Developer account + **Small Business Program** enrollment
- [ ] Google Play Console + 15% tier enrollment
- [ ] Amazon SP-API developer registration (vetting takes weeks)
- [ ] Etsy developer app + commercial approval application
- [ ] Twilio account + sender registration (US A2P 10DLC / regional sender IDs)
- [ ] RevenueCat project; SES (outbound + inbound) domain verification

### 15.3 Phase 1 — MVP

- [ ] ChannelAdapter interface + capability system (§8.3) — *partial (2026-07-15): `App\Contracts\ChannelAdapter` (connect/refreshAuth/registerWebhooks/parseWebhook/capabilities) + `ChannelAdapterManager` driver registry + `CapabilitySet` implemented per-platform matching the §7.8 matrix for all 5 platforms; `fetchOrders`/`fulfill`/`refund`/`cancel`/`sendMessage` deferred until Orders/Inbox modules exist to type against*
- [ ] Shopify adapter: OAuth, scopes, webhooks (+HMAC verify), GDPR webhooks, reconciliation poller — stub only (`ShopifyAdapter` throws `AdapterNotReadyException`, pending Partner app + OAuth setup)
- [ ] WooCommerce adapter: key connect + connector plugin, webhooks + polling fallback — *partial (2026-07-16): real order sync is now live end-to-end. `connect()` validates credentials against the live store (`GET /wp-json/wc/v3/orders`) before ever saving them — previously it blindly trusted whatever was submitted; a real gap, now fixed. `registerWebhooks()` calls Woo's REST API to create real `order.created`/`order.updated`/`order.deleted` webhook subscriptions with a generated per-connection secret, resilient per-topic (partial failure doesn't block the others — the poller covers gaps regardless). `parseWebhook()` verifies the real `X-WC-Webhook-Signature` HMAC-SHA256 and maps the payload via `WooOrderMapper` (accurate real Woo REST v3 order schema → our internal status/fulfillment/payment vocabulary). Public webhook ingress at `POST /hooks/woo/{connection}` (`routes/webhooks.php`, registered outside `/api/v1` per §17.7 "separate ingress", no Sanctum auth — the platform signature is the security boundary). Reconciliation poller (`PollWooOrdersJob` + `orders:poll-woo` scheduled every 15 min per §7.2) fetches anything modified since `last_sync_at`, marks `needs_reauth` on a 401 without throwing. Verified live: real HMAC signatures computed and checked, real credential-validation HTTP calls, duplicate-webhook idempotency, `order.deleted` → local cancellation. Did not stand up a real WordPress+WooCommerce instance (assessed as disproportionate effort — Docker networking + WP-CLI scripting — for marginal gain over what's already proven for real: signature verification, payload mapping, idempotency, HTTP client usage). Connector plugin (§7.2 "recommended UX") still not built — merchants must paste keys manually.*
- [x] Order normalizer → unified model; order events timeline — *(2026-07-15): `NormalizedOrder`/`NormalizedOrderItem` DTOs + `IngestOrderAction` — idempotent upsert on (connection_id, external_id), `order_events` timeline (created/updated, never re-fires "created" on edit per §17.3), item sync, `total_base_currency` computed from the team owner's `base_currency` (`fx_rates` doesn't exist yet, so a genuine mismatch is left `null` rather than fabricated). Verified live: ingested a real order via tinker and confirmed it in the feed.*
- [x] Unified feed API + app screens (feed, filters, global search, order detail) — *(2026-07-15): backend done — `GET /api/v1/orders` (channel/store/status/date/value/tag filters, global search across order#/customer/email/item sku+title, cursor pagination, `history_days` plan-gate, test orders excluded by default) + `GET /orders/{id}`, team-scoped. Mobile app screens are a separate (React Native) deliverable, not yet started.*
- [x] Quick actions: fulfill + tracking, refund, cancel, notes/tags, packing-slip PDF — *(2026-07-16): notes/tags unchanged from 2026-07-15. `FulfillOrderAction`/`RefundOrderAction`/`CancelOrderAction` now call through real `ChannelAdapter::fulfill/refund/cancel()` — capability-checked server-side (`capabilities()->fulfillTracking`/`refunds`/`cancel`) before dispatch, real for WooCommerce (live HTTP calls to the Woo REST API), inert for the other four platforms until their adapters can connect at all. Packing slip (`GET /orders/{id}/packing-slip`) generates a real PDF via `barryvdh/laravel-dompdf` (`GeneratePackingSlipAction` + `resources/views/orders/packing-slip.blade.php`) — this route had been registered pointing at a controller method that didn't exist; fixed. `check_at` (the time-based rule scheduler's index column, see below) is cleared on all three terminal quick actions so a fulfilled/refunded/cancelled order drops out of that scan.*
- [x] Rules engine v1: triggers (new order, high-value, unfulfilled-after-X, digest), condition tree, quiet hours, cooldown, execution log, test-fire — *(2026-07-16): all 12 of §4.4's triggers now have a real evaluation path, not just the 2 from the prior update. `unfulfilled_after_x`/`ship_by_deadline` gate on `controls.threshold_hours` in `RuleEvaluationAction::passesTimingGate()`, driven by a new hourly `orders:check-deadlines` command scanning the (previously unused) `check_at` column. `order_cancelled`/`refund_requested`/`payment_failed` fire on a real status transition detected in `IngestOrderAction` (webhook/poll-sourced only — a merchant's own quick action doesn't re-notify them about something they just did, except `refund_spike`, see below). `order_spike`/`refund_spike` use a plain DB rolling-window count (`controls.spike_count`/`spike_window_minutes`) rather than Redis — a deliberate scope cut (§15.1's Redis/Horizon item is still not started), swappable later without a schema change. `digest` (the Pro custom-rule version, distinct from the free-tier `SendMorningDigests` preset) runs via a new hourly `rules:send-digests` command with per-rule daily/weekly cadence (`controls.digest_frequency`/`digest_time`/`digest_day_of_week`); this surfaced a real bug in the hard `(rule_id,order_id,trigger)` dedup — it only makes sense for order-tied triggers, so a null-order trigger like `digest` could never fire twice, ever, until `alreadyFired()` was fixed to scope only to non-null orders. `low_stock`/`negative_review` are genuinely new: `products`/`reviews` tables + `products:poll-woo` (every 30 min, full-catalog)/`reviews:poll-woo` (hourly, latest 100) polling jobs, gated per-rule by `controls.low_stock_threshold`/`negative_review_max_rating`. All order-less triggers (`digest`/`low_stock`/`negative_review`) route through the same `$order = null` dispatch path, now carrying an optional `$context` array (`RuleEvaluationAction`/`DispatchRuleActionsAction`) so the notification body has real specifics (product/SKU/stock, or rating/review excerpt) instead of a generic placeholder. 48 new Pest tests across this work.*
- [x] Push (FCM/APNs) with custom sounds + deep links; email alerts (SES); SMS (Twilio) + `sms_ledger` — *(2026-07-16): real push via FCM (`kreait/laravel-firebase`, real service-account credential, verified live auth against Google's API), `POST /devices` registration, dead-token pruning on `NotFound`; real email via existing Mail infra, `email_monthly` quota enforced; **SMS is now real** — `SendSmsNotificationAction` calls Twilio's Messages API directly via `Http::` (no SDK dependency, matching the WooCommerce adapter's convention), debiting `sms_ledger` only on a confirmed send, never on a missing phone number or failed API call. This surfaced a real gap: no `phone` column existed anywhere — added to `users`, settable at `/profile/setup`. `GET /notifications` + `POST /notifications/read` (notification center); custom push sounds and deep links are mobile-app concerns, not yet started; SES/Postmark (still on Mailpit for dev), bounce suppression, and notification-storm bundling (§17.4) not yet built*
- [ ] **IAP billing:** RevenueCat SDK + products (`pro_monthly`, `pro_yearly`, `sms_100`, `sms_500`), `/hooks/revenuecat`, entitlement service, restore purchases, plan-gate middleware — *partial (2026-07-16): `POST /hooks/revenuecat` now real (`WebhookController::revenuecat`, outside `/api/v1` per the webhook-ingress pattern) — constant-time `Authorization` header compare against a self-generated `REVENUECAT_WEBHOOK_SECRET` (not something RevenueCat issues; you set the same value in its dashboard). `ProcessRevenueCatEventAction` is the state machine: `INITIAL_PURCHASE`/`RENEWAL`/`PRODUCT_CHANGE`/`UNCANCELLATION` → `subscriptions.status=active`; `BILLING_ISSUE` → `grace` (still treated as entitled by `Subscription::isEntitled()`); `EXPIRATION` → `expired`; `CANCELLATION` deliberately leaves status untouched (§6.1: it only means auto-renew was turned off — the entitlement doesn't actually lapse until `EXPIRATION`). `sms_100`/`sms_500` are handled separately as `NON_RENEWING_PURCHASE` consumables crediting `sms_ledger` (`SmsLedger::REASON_TOPUP_IAP`, which existed unused since the Notifications module — this is what it was for). New `revenuecat_events` table makes every event idempotent by RevenueCat's own event id before any side effect runs, since a redelivered webhook must never double-credit SMS. `app_user_id = user_id` resolves straight to our `User` → `currentTeam()`, matching §6.1's identity-linking design. Still pending: the RevenueCat SDK itself (React Native/mobile-side, nothing to build here until that project exists), restore-purchases (same), and a dedicated plan-gate middleware (entitlements are currently checked per-Action — e.g. `ConnectStoreAction`/`CreateRuleAction` — rather than centralized in middleware; works correctly today, a refactor not a gap).*
- [ ] **Admin panel (Inertia.js + React) per §8.7:** admin auth + 2FA + roles, KPI dashboard, customers module (list/detail/actions), plans & limits editor (DB-driven entitlements), promotions (offer-code campaigns + server comps), messaging center (segments, broadcasts, templates, delivery stats), **support inbox (live chat)**, ops/health boards, app config (min version, maintenance), audit log — *partial (2026-07-16): dedicated `admin` guard + `admin_users` table (roles: superadmin/support/readonly) via Fortify at `/admin/login`; Shopify Polaris UI with a real app-shell nav (`Frame`+`Navigation`+`TopBar`, matching genuine Shopify Admin — dark top bar with user-menu/sign-out, collapsible left sidebar with icons, replacing the earlier plain horizontal text-link bar) — Dashboard/Customers/Plans & Limits, each page `fullWidth` so content isn't squeezed into a narrow centered column. `admin_audit_log` built + every write action logs to it; `admin.write` middleware blocks the readonly role at the route level. **Dashboard (§8.7.1)**: signups, DAU/MAU, active trials + conversion %, paying subs (monthly/yearly split), MRR/ARR/ARPU/churn (IAP prices hardcoded from §5 pending a real prices table), top platforms, notification volume, SMS cost-vs-revenue, activation funnel — all computed live from real data; support-inbox metrics and "paywall seen" omitted (no `support_threads`/impression tracking). **Customers (§8.7.2)**: search/filter (plan/platform/signup/last-active) + CSV export + detail page (profile, devices, connected stores, subscription, rules, SMS ledger, notification volume, funnel position); country/LTV filters and support-chat history omitted (not tracked anywhere). **Customer actions**: extend trial, grant complimentary Pro, grant bonus SMS credits, force logout (revokes Sanctum tokens), suspend/unsuspend (added `users.suspended_at` + a `user.not_suspended` middleware that actually blocks the mobile API — verified live) — all audit-logged; GDPR export + account delete explicitly deferred. **Plans & Limits (§8.7.3)**: every `plan_limits` value live-editable, verified end-to-end that an admin edit reflects in the next mobile `/me` call with zero app changes; feature flags/paywall copy/SMS top-up pack editing deferred (no tables). Security fix along the way: `StoreConnection.credentials` was not in `$hidden` — fixed before building any admin view that touches store connections. Promotions (§8.7.4), messaging center (§8.7.5), support inbox (§8.7.6), and operations/health (§8.7.7) are fully deferred — their tables (`segments`, `broadcasts`, `support_threads`, etc.) don't exist yet. 2FA columns still unused pending a settings UI.*
- [ ] **In-app support chat:** user-side chat screen, Reverb delivery, push + email fallback, inbound email threading, canned replies
- [ ] Onboarding UX: email→OTP→profile→connect-store flow, <60s store connection, empty states, notification bundling
- [ ] Edge-case test suite covering §17 (auth, connections, sync idempotency, notification storm, IAP, offline)
- [ ] **Trial system:** 7-day server-side trial, countdown UX, day-5/day-7 notifications, trial-end downgrade flow exactly per §6.4 (pause stores, freeze rules/credits, truncate display history) — *partial (2026-07-15): `GrantTrialSubscriptionAction` grants the trial on team creation (`subscriptions.status=trial`, `trial_ends_at` = Pro's admin-editable `trial_days` limit, default 7); countdown UX, day-5/7 notifications, and the full §6.4 downgrade behavior (pause stores, freeze rules/credits) pending — no store/rule/credit tables exist yet to freeze*
- [ ] **Settings & account (§4.8):** notification preferences per channel + quiet hours + sound selection, SMS credit balance + top-up, subscription management (native IAP + restore purchases + store links), data export request, account deletion (GDPR), dark mode/language — *partial (2026-07-16): `POST /auth/logout` + `/auth/logout-all` (real gap — mobile had no way to sign out at all; logout revokes only the current Sanctum token, logout-all revokes every token for the user). Personal `notification_preferences` (push/email/sms enabled flags + quiet_hours_start/end/timezone + sound) via `GET/PUT /settings/notifications` — distinct from a rule's own per-rule `controls.quiet_hours`/mute (§4.4): this is a per-recipient delivery gate checked *after* a rule fires, wired into `SendPushNotificationAction`/`SendEmailNotificationAction` (mute/quiet-hours skips the actual FCM/mail send but the in-app notification-center record is always written — the center is a record of what fired, not proof of delivery). Fixed a real stale gap along the way: `GET /me`'s `sms_balance` was hardcoded `null` with a comment saying `sms_ledger` didn't exist yet — it did, from the Notifications module — now computed for real (`SmsLedger::currentBalance()`). **Data export** (`POST /account/data-export`): compiles a real JSON export (user/team/members/connections/orders/rules — relies on `StoreConnection`'s own `#[Hidden(['credentials'])]` rather than manual field selection, so credentials can never leak into it) and emails it as a real attachment. **Account deletion** (`POST /account/delete-request`): soft-delete + 30-day grace period, not immediate hard delete — `users`/`teams` gained `deleted_at`; deleting an owner soft-deletes their team too (everything hangs off `team_id`, so an owner's departure takes the business with it), and other members' `Team::belongsTo` relation simply stops resolving once the team is trashed, dropping their access without touching their own account. `accounts:purge-deleted` (scheduled daily) hard-deletes both past the grace period, cascading through existing FKs. No restore endpoint yet — re-signing in during the grace period is explicitly rejected (`VerifyOtpAction` now checks for a soft-deleted match first) rather than crashing on the still-occupied unique email column, which is what would have happened without that check. This is the **seller self-service** version — distinct from the admin panel's own "GDPR export + account delete" customer actions (§8.7.2), which are still deferred. SMS top-up purchase and native IAP subscription management (upgrade/downgrade/restore) are correctly left to the not-yet-built RevenueCat/IAP module; dark mode and language are mobile-app/client-only concerns, nothing to build server-side for "English first."*
- [ ] Paywall screens (second-store trigger, locked-teaser notifications, yearly pre-selected)
- [ ] Analytics-lite (today/7/30 per channel) + `daily_stats` aggregation — *partial (2026-07-16): `daily_stats` schema (team_id, connection_id, date, orders_count, revenue, revenue_base, aov, refunds) + `AggregateDailyStatsAction`/`analytics:aggregate-daily` (scheduled `dailyAt('00:15')`, `--date=` for backfill), test orders excluded (§17.3). `GetAnalyticsSummaryAction` (`GET /analytics/summary?range=today|7d|30d`) — "today" is **always** computed live from `orders`, never from `daily_stats` (that table only holds finished days); 7d/30d merge historical `daily_stats` rows with live "today". Per-channel breakdown, period-over-period % change, and goal tracking (current vs. best historical calendar month, grouped in PHP rather than SQL `DATE_FORMAT`/`strftime` to stay portable across MariaDB/SQLite) are Pro-only (`analytics_level=full`; Free is `range=today` only, enforced server-side per §5.1's "Analytics: Today only | Full"). `GetTopProductsAction` (`GET /analytics/products?range=`) — same plan gate, groups by SKU falling back to title for SKU-less items. Multi-currency handled the same honest way as `total_base_currency` elsewhere: only resolvable-currency orders contribute to `revenue_base`, never a fabricated conversion. **Morning digest** ("Yesterday: 23 orders, $1,840. Best seller: X.") is a baseline preset available to every plan including Free (§4.4 "preset alerts only... daily digest") — `SendMorningDigestAction` + `notifications:send-morning-digests` (hourly, only acts on teams whose owner is currently in their local 7am hour, `teams.last_digest_sent_at` guards against double-sends within that hour), reuses `SendPushNotificationAction` so the owner's own notification preferences (§4.8) still apply. Verified live end-to-end against ddev: real orders created, `analytics:aggregate-daily` run for real (revenue correctly jumped from live-today-only to include the aggregated prior day), real `/analytics/summary` and `/analytics/products` HTTP responses, and the digest action produced correct real content. Found and fixed a real cross-database bug along the way (see memory: DATE_FORMAT/date-column-comparison gotcha). Home-screen widget is a mobile-app-only concern, nothing to build server-side beyond the summary endpoint it already reads from.*
- [ ] Onboarding flow + connection health screen
- [ ] App Store / Play Store review prep (IAP metadata, privacy labels, demo account) → **Launch**

### 15.4 Phase 2

- [ ] eBay adapter (Fulfillment/Post-Order APIs, notifications, feedback poller)
- [ ] Etsy adapter (v3 polling with cursor strategy + rate budget)
- [ ] Unified inbox: eBay + Etsy messaging, SES email threading for Shopify/Woo, reply templates, assignment
- [ ] Team seats, roles, per-member notification routing — *partial (2026-07-16): built ahead of Phase 2 schedule since it's core to making the mobile app usable by a real team, not just its owner. `team_invites` table + `InviteTeamMemberAction` (`POST /team/invite`, enforces `team_seats` counting active members + outstanding pending invites as reserved seats) sends a real invite email (`TeamInviteMail`). No separate "accept invite" endpoint exists in §10's API surface, so redemption is automatic: `RedeemTeamInviteAction`, wired into `SetupProfileAction`, matches by email at onboarding time and joins the invited team as a member instead of creating a new owned team — verified live end-to-end (real OTP signup, real invite email via Mailpit, invitee's `/me` showed the owner's team + `role: agent`, and their own invite attempt correctly 403'd). A user who already has a team keeps it — invites for their email stay pending (the mobile app has no team-switcher, so this is a deliberate one-team-per-user scope decision, not a limitation of the schema). `GET /team` (roster + pending invites) and `PUT /team/{member}` (`UpdateTeamMemberAction` — role/store_visibility; the owner's own row is immutable) are done. Role enforcement (`EnsureTeamRole` middleware, aliased `team.role`) gates every write route (connections/rules/order notes+tags/team invite+update) to `owner`/`manager`; `agent`/`viewer` get 403 but keep full read access — `agent`'s "+ inbox" privilege is moot until the unified inbox module exists. Member-level store visibility restricts the order feed and order-detail access to a member's `store_visibility` connection ids when set. Per-member notification routing was already covered by `NotifyMemberAction` in the Notifications module. All mobile API controllers (`Connection`/`Order`/`Rule`/`Me`) were refactored from `$user->ownedTeam` to a new `$user->currentTeam()`/`currentTeamMember()` resolver so invited members actually see the team's data, not just its owner — admin-panel customer lookups intentionally kept `ownedTeam` (they target the billing-owner account, a different concept). 13 new Pest tests, 154 total passing, Pint/PHPStan clean.*
- [ ] Home-screen widgets (iOS/Android) + morning digest
- [x] Ship-by-deadline + negative-feedback triggers — *(2026-07-16): built ahead of schedule as part of the Phase 1 rules-engine work above (`ship_by_deadline`/`negative_review`), not deferred to Phase 2 after all.*
- [ ] Win-back intro offers (StoreKit promotional offers)

### 15.5 Phase 3

- [ ] Amazon adapter (SQS notifications, RDT-gated PII, feeds for tracking, template messaging)
- [ ] Spike/anomaly triggers, goal tracking, multi-currency consolidation — *partial (2026-07-16): spike triggers (`order_spike`/`refund_spike`) and goal tracking (Analytics-lite, §15.3) both built ahead of schedule. Multi-currency consolidation still pending — no `fx_rates` table exists yet, so `total_base_currency`/`revenue_base` stay `null` on any currency mismatch rather than a fabricated conversion.*
- [ ] TikTok Shop adapter evaluation

### 15.6 Verification gates (each phase)

- [ ] Adapter integration tests against sandbox stores (Shopify dev store, Woo test site, eBay sandbox, Etsy/Amazon sandbox)
- [x] Rules-engine unit tests (condition tree, dedup, quiet hours, timezone) — *(2026-07-16): `ConditionEvaluatorTest`, `RuleEvaluationActionTest` (now covering all 12 triggers' timing/spike gates), `RulesApiTest`, `SendRuleDigestsTest`, `CheckLowStockActionTest`, `CheckNegativeReviewActionTest` — dedup (hard per-order + cooldown), quiet hours, and team-owner timezone fallback all covered.*
- [ ] Trial/downgrade end-to-end test (trial grant → expiry → freeze → re-upgrade restore)
- [ ] IAP sandbox testing (purchase, renewal, grace, refund revocation, restore) on both stores
- [ ] Load test webhook ingestion; notification latency target < 5s from platform event

---

## 16. Appendix — Competitor Feature & Pricing Benchmark (July 2026)

Researched from live pricing pages and current comparisons. Use to sanity-check our tiers; re-verify before launch.

### 16.1 Multichannel management tools (desktop/web)

| Product | Free tier | Paid pricing | Trial | Pricing axis | Key features |
|---|---|---|---|---|---|
| **Sellbrite** (GoDaddy) | ✅ up to 30 orders/mo | $29/mo (100 orders), $79/mo (500 orders) | 14 days, no card | **Order volume** | Listings, inventory sync, order mgmt, shipping via ShipStation; Amazon/eBay/Etsy/Walmart/Shopify/Woo |
| **LitCommerce** | Free product-feed tool only | From $29/mo | 7 days, cancel anytime | **Listings count + channels** (orders unlimited) | Bulk listing, templates, AI listing optimization, 15-min inventory/order sync, sales report; 20+ channels incl. TikTok Shop/Temu |
| **Zoho Inventory** | ✅ 50 orders/mo, 1 user | $29 → $79 → $129 → $249/mo (annual) | 14 days | **Order volume + users + locations** (paid add-ons per extra) | Full inventory/warehouse/order suite, automation, barcode, reports; Shopify/Amazon/eBay/Etsy |
| **Multiorders** | — | From ~$64/mo | trial | Order volume | Inventory sync, shipping automation, order routing rules |
| **4Seller** | ✅ **largely free** | monetizes via shipping services | — | — | Orders, inventory, listings, fulfillment; Amazon/Walmart/eBay/TikTok/Etsy/Temu/SHEIN |

### 16.2 Notification apps (Shopify ecosystem)

| Product | Free tier | Paid pricing | Trial | Pricing axis | Key features |
|---|---|---|---|---|---|
| **Notify Me!** | ✅ (see detail below) | $9.90 → $19.90 → $39.90/mo | 7 days | **Alert/preorder volume + overages** | Back-in-stock/preorder alerts via email/SMS/push |
| **Ordersify** | ✅ limited | $9.99–$39.99/mo | free plan | Notification volume + branding | Branded alerts, multilingual, low-stock reports |
| **Smart Notifications** | — | $19/mo unlimited | 7 days | Flat | **Rule-based** order emails to staff/vendors (closest to our rules engine, Shopify-only) |
| **STOQ** | — | from $10/mo | trial | Volume | Restock alerts email/SMS/push |

**Notify Me! — full plan detail (reference model for our own plan presentation):**

| | Lite (Free) | Kickstart $9.90/mo | Starter $19.90/mo | Standard $39.90/mo |
|---|---|---|---|---|
| Yearly option | — | $95/yr (save 20%) | $191/yr (save 20%) | $383/yr (save 20%) |
| First-month intro | — | $3.96 (60% off) | $7.96 (60% off) | $15.96 (60% off) |
| Alerts (Email/SMS/Push) | 10 restock notifications | 500 | 500 | 1,500 |
| Preorders | 5 | 500 (no transaction fee) | 500 | 1,500 |
| Overage | — | $0.20/preorder above limit | $0.20 | $0.15 (cheaper at scale) |
| Wishlist actions | 50 | 2,000 | 2,000 | 10,000 + priority support |
| Extras | Widgets auto-match theme, low-stock widget | Widget/email customization, partial-pay | Brand emails, reports, low-stock alerts, page-builder integration | Discounts for preorders, collection-page widgets, email templates, unlimited plans in-app |
| Trial | — | 7-day | 7-day | 7-day |

**Pricing mechanics worth copying from Notify Me:**

1. **Numeric limits on everything, even Free** — Free isn't "features removed," it's tiny quotas (10 alerts). Users experience the full product and hit walls naturally. Maps directly to our admin-editable `plan_limits`.
2. **Overage pricing instead of hard stops** — $0.20/unit above limit keeps revenue flowing without forcing an upgrade decision mid-month. Our SMS top-up packs are our version; consider auto-suggested top-up at 80% consumption.
3. **First-month intro discount** — implementable for us via StoreKit/Play **introductory offers** on the monthly products. Add to §6 win-back and initial paywall.
4. **.99 price endings and "save 20%" yearly framing** — cosmetic but proven in this market; our Free/$5.99/$17.99·$172.99/$44.99·$429.99 (§5, revised 2026-07-16) framing matches this directly — the .99 endings and 20%-off yearly framing were adopted deliberately from this research, not coincidentally similar.
5. **Per-plan feature bullets written as customer-visible benefits** — our App Store listing should present plans this way (see §5.1).

### 16.3 What this means for us

Revised 2026-07-16 after moving from a flat Free/Pro model to 4 tiers (§5) — this section originally justified a single flat Pro price; the live re-check below is what actually informed the 4-tier redesign, not a retrospective rationalization:

1. **Ordersify's real structure (Free → $9.99 → $19.99 → $39.99) is nearly a direct match** for our shape and price band (Free → $5.99 → $17.99 → $44.99) — strong external validation that 4 tiers in this range is normal for the category, not overbuilt.
2. **The one counter-signal:** Smart Notifications (the closest *functional* match — rules-based order emails) is flat $19/mo with zero tiers, suggesting the rules-engine feature itself doesn't reward tiering. This is why tiers scale on **store count / team seats / channel breadth** (matching Listing Mirror's axes) rather than gating the rules engine itself — Starter+ all get unlimited-ish rule *access* (Starter capped at 5, Pro+ unlimited), with only the two "anomaly" triggers (`order_spike`/`refund_spike`) reserved for Premium.
3. **7-day no-card trial matches LitCommerce/Smart Notifications; Sellbrite/Zoho use 14 days** — 7 is defensible; consider 14 if trial→paid conversion tests low (admin-configurable per §8.7). The trial now grants Premium specifically (§6.3) rather than a generic "Pro," so it demonstrates the full tier ladder before the seller picks one.
4. Free tiers are standard (Sellbrite 30 orders, Zoho 50 orders, Ordersify/Notify! both free) — our 1-store free tier is in line; 4Seller's free-everything model is the main pricing threat to monitor.
5. Nobody compared — including the back-in-stock/preorder apps and the multichannel-listing tools — is mobile-first with cross-channel custom rules and phone-based quick actions (fulfill/refund/cancel). None of them let you *act* on an order, only get notified about it. The positioning gap holds, and is in fact wider than originally assessed.

---

## 17. Edge-Case Catalog (must-handle)

Grouped by domain; each item is a required behavior, not a suggestion. QA test plans derive from this list.

### 17.1 Auth & accounts

- OTP expired / wrong 5× → invalidate code, show resend; account locked 15 min after repeated cycles.
- OTP requested for typo'd email → nothing leaks; user retries with correct email (no orphan accounts — user record created only on successful verify).
- Same person uses email OTP once, Apple sign-in later → merge by verified email into one account.
- Account deleted mid-session on another device → all tokens revoked, app returns to auth screen gracefully.
- OTP email lands in spam → resend option + "check spam" hint after 30s; monitor deliverability (§4.1).

### 17.2 Store connections

- Platform token expired/revoked (password change, app uninstalled from Shopify side) → connection marked `needs_reauth`, user push-notified once, health screen shows Fix-it; **no silent data gaps** — on reconnect, backfill missed window.
- Shopify `app/uninstalled` webhook → mark disconnected immediately, stop billing-relevant counting, purge per Shopify GDPR webhooks on schedule.
- Woo site down / SSL broken / security plugin blocks REST → adaptive retry with backoff, clear troubleshooting steps in-app, auto-recover when site returns.
- Duplicate connection of the same store → detect by shop domain/marketplace ID, block with friendly message.
- Downgrade with 4 stores connected → extra stores paused per §6.4 rules, user picks which one stays active (default: first connected).
- Marketplace suspends the seller's account → surface platform error verbatim-translated, don't loop retries.

### 17.3 Orders & sync

- **Duplicate webhooks** (all platforms redeliver) → idempotent upsert on `(connection_id, external_id)` + event de-dup by delivery ID.
- **Out-of-order events** (fulfillment webhook before order webhook) → upsert order shell, reconcile on next event/poll.
- Order edited after fulfillment (address change, item swap) → new `order_events` entry, feed shows "updated" marker, rules do NOT re-fire "new order" (dedup key).
- Partial fulfillment / partial refund → statuses reflect partial states; rules can target "fully unfulfilled" vs "any unfulfilled".
- 100+ line-item orders → paginated line items, packing slip paginates.
- Test orders (Shopify test / eBay sandbox) → flagged, excluded from analytics & digests by default.
- Digest windows across DST/timezone changes → compute in user timezone at send time.
- Currency mismatch / FX rate unavailable → fall back to last known rate, mark converted values approximate.

### 17.4 Notifications

- Push token invalid (app uninstalled) → prune on FCM error, stop counting toward quota.
- OS-level notifications disabled → detect on app open, banner: "Push is off — alerts can't reach you" with deep link to OS settings.
- **Notification storm** (flash sale: 200 orders in 10 min) → auto-collapse to bundled summaries ("47 new orders in the last 10 min · $3,912"), never 200 pings; SMS rules hard-capped per hour (admin-configurable).
- SMS to unsupported country / carrier failure → don't debit credit on failure, surface in rule execution log.
- Email hard bounce → suppress address, notify user in-app to fix.
- Quiet hours + timezone travel → quiet hours follow the profile timezone; changing timezone re-evaluates scheduled digests.

### 17.5 Billing & IAP

- Refund/chargeback → RevenueCat revocation webhook → downgrade per §6.4 same-day.
- Grace period (card failure) → Pro stays on, banner shown; expiry → downgrade flow.
- Plan change monthly↔yearly mid-cycle → store handles proration; entitlement follows RevenueCat's effective product.
- Purchase on iPhone, opens Android tablet → entitlement is account-level (server), restore works cross-platform.
- Receipt validation/RevenueCat outage → fail-open: cached entitlements honored up to 48h TTL, never lock out a paying user.
- Trial abuse (new email, same stores) → store-connection fingerprint match → no second trial, paywall directly.
- Purchase while offline / interrupted → StoreKit unfinished-transaction replay on next launch.

### 17.6 Inbox & support chat

- Platform rejects message (Amazon template violation, eBay policy) → composer pre-validates; on rejection show reason + fix, never silently drop.
- Inbound email reply can't match a thread → land in admin "unmatched" queue, staff attach manually.
- User replies to a resolved support thread → auto-reopen.
- Attachment too large → client-side compress/limit with clear error.

### 17.7 App & platform

- Offline → cached feed browsable, actions queued-disabled with "you're offline" state; auto-retry on reconnect.
- App version below minimum (killed API) → force-update screen (from `/config`).
- WebSocket drop → silent fallback to polling + push; reconnect with backoff.
- Server deploy/maintenance → maintenance banner from `/config`, webhook ingestion never down (separate ingress) — platforms retry but only within their windows; reconciliation pollers close any gap.
- Clock skew on device → all times server-authoritative; display relative times.
- Platform API deprecations (Shopify quarterly API versions) → adapter pins version, upgrade task each quarter (checklist §15.6).

---

*End of specification — v1.4, July 2026.*

