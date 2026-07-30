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
| Subtitle | 30 characters | `Real-time orders. Zero stress.` | 30 |
| Promotional Text | 170 characters | `Your phone buzzes the second an order lands — not next time you check your laptop. Smart rules watch stock, reviews & spikes so you don't have to.` | 146 |
| Keywords | 100 characters, comma-separated | `shopify,orders,inventory,alerts,ecommerce,seller,rules,notifications,stock,dropship` | 83 |
| What's New (v1.0) | 4,000 characters | `Your store just got a heartbeat. Connect Shopify, set your first rule, and feel every order the second it happens — right from your pocket. This release: live order feed, custom rules, stock alerts, and your business at a glance.` | 229 |
| Description | 4,000 characters | See [Full description](#full-description-both-platforms) below | 2,253 |
| Copyright | short text | `© 2026 StockBeat` | — |

**Promotional Text is the only field you can edit without a new build review** — good place to push time-sensitive callouts (a new platform launch, a pricing change) between releases.

**Screenshots:** required per device size class you support (6.7", 6.5", and iPad if you support tablet). Exact pixel dimensions change periodically — pull the current required sizes from App Store Connect's Media Manager at submission time rather than trusting a hardcoded number here. Plan for at least 3-5 screenshots per size: order feed, rule builder, order detail/quick actions, analytics dashboard, notification center.

**Age rating:** run Apple's questionnaire fresh at submission — nothing in StockBeat (no UGC between strangers, no user-to-user chat beyond seller↔customer support threads) should trigger anything above 4+, but don't guess, answer it for real.

---

## Google Play Console

| Field | Limit | Content | Count |
|---|---|---|---|
| Short description | 80 characters | `Your Shopify store, watched 24/7. Instant alerts, smart rules, no laptop.` | 73 |
| Full description | 4,000 characters | See [Full description](#full-description-both-platforms) below | 2,253 |
| Feature graphic | 1024×500px, required | Banner — logo (`public/assets/logo1.png`) on the marketing site's background color (`#F8FAF3`), with "StockBeat" wordmark and a short tagline overlay. Design this fresh, don't stretch the app icon. | — |
| App icon | 512×512px | Export from `public/assets/logo1.png` at full res | — |
| Screenshots | min 2, phone required | Same shot list as Apple, sized for Play's phone/7"/10" tablet buckets | — |

**Data safety form:** this is the one Play-specific field with no Apple equivalent and no fixed word count — it's a structured questionnaire about what data StockBeat collects (email, order data, device push tokens, usage analytics if any) and whether it's shared with third parties (Firebase for push, RevenueCat for billing, Twilio for SMS). Fill this out against the real data flows in `docs/mobile/*.md`, not from memory — getting this wrong is a common rejection reason and worth a careful pass against the actual API surface when you're ready to submit.

---

## Full description (both platforms)

*2,253 characters — same copy works for both App Store (4,000 limit) and Play Store (4,000 limit).*

```
Your Shopify store never sleeps. Now you don't have to babysit it either.

StockBeat turns your phone into mission control — the moment an order lands, you know. The second stock runs low, you know. The instant a review needs a reply, you know. No laptop, no five open tabs, no wondering what happened while you were away.

EVERY ORDER, THE SECOND IT HAPPENS
Watch orders roll in live — customer, total, fulfillment and payment status, a full timeline on every single one. Fulfill with tracking, refund in full or in part, cancel, tag, or message the customer, all with your thumb. Batch it across dozens of orders when a launch goes big.

RULES THAT ACTUALLY GET YOUR BUSINESS
Stop drowning in notifications that don't matter. Build rules in plain English — WHEN a trigger fires, IF conditions match, THEN act. New orders, low stock, stale inventory, review ratings, order and refund spikes — combine conditions on total, SKU, country, repeat buyers, however you actually run things. Push, email, or SMS, your call, with quiet hours so 2am isn't part of the deal.

YOUR NUMBERS, WITHOUT THE SPREADSHEET
Today, this week, this month — revenue, orders, average order value, tracked against your best month ever. Wake up to a morning digest that already knows what happened while you slept.

STOCK THAT WATCHES ITSELF
Get pinged the moment something drops below your line, push corrected counts straight to Shopify, and catch dead stock before it quietly kills your margin. Cost price tracking means profit is a fact, not a guess.

BRING YOUR TEAM
Add staff with real permissions, not a shared password. Upgrade for a unified inbox, review replies, payout tracking, and an AI assistant that actually knows your store — ask it anything, or let it help you build the next rule.

PICK YOUR SPEED
Free — 1 store, order alerts, daily digest, no card required.
Starter, $5.99/mo — 3 stores, 5 custom rules, SMS + email.
Pro, $17.99/mo — 10 stores, unlimited rules, unified inbox, full analytics, team seats. 7-day free trial.
Premium, $44.99/mo — unlimited stores, top-tier alert volume, priority support.

Shopify is live right now. WooCommerce, eBay, Etsy, Amazon, and TikTok Shop are on the way.

No password to remember, ever — one email code and you're in.
```

---

## Before you actually submit

- [ ] Re-verify every character count in this file with a live paste into App Store Connect / Play Console — console-side trimming rules (e.g. how emoji or line breaks count) can differ slightly from a plain `len()` count.
- [ ] Confirm `/privacy`, `/terms`, `/contact` are live and not placeholder pages before listing those URLs.
- [ ] Pull current screenshot pixel requirements from each console at submission time — they shift periodically.
- [ ] Fill Play's Data Safety form against the real data flows, not this doc.
- [ ] Update this file the moment a second platform (WooCommerce, eBay, etc.) launches — the "coming soon" framing throughout needs to come out.
