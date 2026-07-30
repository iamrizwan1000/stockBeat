# StockBeat — App Store & Play Store Listing Content

Copy-paste-ready content for both submission consoles. Character counts are verified (see the count next to each field) — don't edit the text without re-checking the count, most of these limits reject on save.

**Scope note:** as of 2026-07-31, only Shopify is a live connection — WooCommerce, eBay, Etsy, Amazon, and TikTok Shop are built but not yet launched (`docs/mobile/connections-api-reference.md`). Every field below is written to reflect that honestly, same discipline as the marketing homepage fix. **Update this file (and both store listings) the moment another platform launches** — don't let this drift the way the homepage briefly did.

---

## Shared identity (use identically on both platforms)

| Field | Limit | Content | Count |
|---|---|---|---|
| App Name | 30 characters | `StockBeat: Shopify Orders` | 25 |
| Support URL | required, valid URL | `https://stockbeat.qistpay.org/contact` | — |
| Privacy Policy URL | required, valid URL | `https://stockbeat.qistpay.org/privacy` | — |
| Marketing URL | optional | `https://stockbeat.qistpay.org` | — |
| Contact email | required | `support@stockbeat.qistpay.org` | — |
| Category (primary) | 1 required | Business, or Shopping | — |
| Category (secondary) | optional | Productivity | — |

---

## Apple App Store Connect

| Field | Limit | Content | Count |
|---|---|---|---|
| Subtitle | 30 characters | `Rules, alerts & stock control` | 29 |
| Promotional Text | 170 characters | `Instant alerts the moment a Shopify order lands, plus custom rules for low stock, reviews & more. WooCommerce, eBay, Etsy, Amazon & TikTok Shop support coming soon.` | 164 |
| Keywords | 100 characters, comma-separated | `shopify,orders,inventory,alerts,ecommerce,seller,rules,notifications,stock,dropship` | 83 |
| What's New (v1.0) | 4,000 characters | `Welcome to StockBeat! Connect your Shopify store, set up your first rule, and get real-time order alerts wherever you are. This release includes the unified order feed, custom rules engine, inventory alerts, and analytics dashboard.` | 232 |
| Description | 4,000 characters | See [Full description](#full-description-both-platforms) below | 2,052 |
| Copyright | short text | `© 2026 StockBeat` | — |

**Promotional Text is the only field you can edit without a new build review** — good place to push time-sensitive callouts (a new platform launch, a pricing change) between releases.

**Screenshots:** required per device size class you support (6.7", 6.5", and iPad if you support tablet). Exact pixel dimensions change periodically — pull the current required sizes from App Store Connect's Media Manager at submission time rather than trusting a hardcoded number here. Plan for at least 3-5 screenshots per size: order feed, rule builder, order detail/quick actions, analytics dashboard, notification center.

**Age rating:** run Apple's questionnaire fresh at submission — nothing in StockBeat (no UGC between strangers, no user-to-user chat beyond seller↔customer support threads) should trigger anything above 4+, but don't guess, answer it for real.

---

## Google Play Console

| Field | Limit | Content | Count |
|---|---|---|---|
| Short description | 80 characters | `Real-time Shopify order alerts, custom rules, inventory tracking, on the go.` | 76 |
| Full description | 4,000 characters | See [Full description](#full-description-both-platforms) below | 2,052 |
| Feature graphic | 1024×500px, required | Banner — logo (`public/assets/logo1.png`) on the marketing site's background color (`#F8FAF3`), with "StockBeat" wordmark and a short tagline overlay. Design this fresh, don't stretch the app icon. | — |
| App icon | 512×512px | Export from `public/assets/logo1.png` at full res | — |
| Screenshots | min 2, phone required | Same shot list as Apple, sized for Play's phone/7"/10" tablet buckets | — |

**Data safety form:** this is the one Play-specific field with no Apple equivalent and no fixed word count — it's a structured questionnaire about what data StockBeat collects (email, order data, device push tokens, usage analytics if any) and whether it's shared with third parties (Firebase for push, RevenueCat for billing, Twilio for SMS). Fill this out against the real data flows in `docs/mobile/*.md`, not from memory — getting this wrong is a common rejection reason and worth a careful pass against the actual API surface when you're ready to submit.

---

## Full description (both platforms)

*2,052 characters — same copy works for both App Store (4,000 limit) and Play Store (4,000 limit).*

```
StockBeat is mission control for Shopify sellers who don't want to live inside a laptop dashboard.

Every order lands in one real-time feed the moment it comes in — customer, total, fulfillment and payment status, and a full timeline, all at a glance. Fulfill with tracking, issue a full or partial refund, cancel, tag, or message the customer, right from your phone. Batch it across dozens of orders when volume picks up.

RULES THAT KNOW YOUR BUSINESS
Compose rules in plain terms — WHEN a trigger fires, IF conditions match, THEN act. New orders, low stock, stale inventory, review ratings, order and refund spikes, and more, with AND/OR conditions on total, SKU, country, and repeat-buyer status. Get notified by push, email, or SMS, with a custom sound and priority per rule, quiet hours, and cooldowns so you're alerted to what matters without being buried in noise.

KNOW WHERE YOU STAND, TODAY
Today, 7-day, and 30-day revenue, order count, and average order value, plus goal tracking against your best month. A morning digest tells you what happened while you slept.

INVENTORY THAT ALERTS ITSELF
Push real stock corrections straight to Shopify, get notified the moment something drops below your threshold, and catch dead stock before it becomes a write-off. Cost price tracking means profit math is never a guess.

BUILT FOR TEAMS
Invite staff with role-based permissions. Higher plans add a unified customer inbox, review replies, payout tracking, and an AI assistant that can answer questions about your business and help build rules.

PLANS
Free: 1 store, new-order alerts, daily digest.
Starter ($5.99/mo): up to 3 stores, 5 custom rules, SMS + email alerts.
Pro ($17.99/mo): up to 10 stores, unlimited rules, unified inbox, full analytics, team seats — 7-day free trial.
Premium ($44.99/mo): unlimited stores, highest alert volume, priority support.

Shopify support is live today. WooCommerce, eBay, Etsy, Amazon, and TikTok Shop connections are coming soon.

No password to remember — sign in with a one-time email code and you're in.
```

---

## Before you actually submit

- [ ] Re-verify every character count in this file with a live paste into App Store Connect / Play Console — console-side trimming rules (e.g. how emoji or line breaks count) can differ slightly from a plain `len()` count.
- [ ] Confirm `/privacy`, `/terms`, `/contact` are live and not placeholder pages before listing those URLs.
- [ ] Pull current screenshot pixel requirements from each console at submission time — they shift periodically.
- [ ] Fill Play's Data Safety form against the real data flows, not this doc.
- [ ] Update this file the moment a second platform (WooCommerce, eBay, etc.) launches — the "coming soon" framing throughout needs to come out.
