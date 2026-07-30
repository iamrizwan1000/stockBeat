# StockBeat Mobile — Network Resilience & Edge-Case Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md` — read that first if you haven't. **Read this doc before building any screen with a submit button** — it's the single cross-cutting reference for "what happens on a slow 2G connection, a double-tap, or a request that's still in flight when the user navigates away," rather than repeating the same guidance piecemeal across every other doc.

Added 2026-07-30 and extended 2026-07-31, prompted by two real audits that found (and fixed) genuine double-submission and race-condition bugs — not just documentation gaps. The second pass covered everything money-adjacent: AI credits, subscriptions/trials, alerts, and customer messaging. The behaviors below reflect the real, current, fixed state — see each endpoint's own reference doc for anything not covered here.

---

## General client-side patterns

**Always disable a submit button the moment it's tapped, and only re-enable it once the response comes back (success or error).** This is basic, standard practice and remains the first line of defense — server-side guards below are a safety net for when this doesn't happen (a slow tap-tap before the UI updates, a network blip that delays the disabled-state render), not a replacement for it.

**Never blind-retry a POST/PUT/DELETE after a timeout without first checking server state.** If a request times out, you genuinely don't know whether it succeeded server-side before the response was lost in transit. For anything that mutates state, the safe pattern is: **re-fetch first** (e.g. `GET /orders/{id}` to see if it's already fulfilled, `GET /connections` to see if the store already connected), and only resubmit if the re-fetch shows the action didn't actually happen. Blindly firing the same POST again is exactly the "double-tap" scenario this doc's server-side guards exist for — they'll catch it, but a well-behaved client shouldn't rely on that as its primary strategy.

**A request that's still in flight when the user navigates away or backgrounds the app still completes server-side.** Nothing in this API cancels server-side work just because the client stopped listening. When the user returns to a relevant screen, re-fetch its data rather than assuming nothing changed while they were away — this is standard practice, not something unique to this API, but worth stating plainly since some of the flows below (store connect, OAuth) specifically call this out.

**There is no offline queueing built into this API today.** A request made while genuinely offline just fails (network error) — there's no "queue it and send when back online" mechanism on the server side, and nothing here assumes the client has built one either. If you build client-side offline queueing, be aware that a queued-then-later-sent mutation is exactly the "was this already processed by some other path" scenario the idempotency guards below are designed for, so it's safe to build on top of — just don't assume the server will do anything special to accommodate a delayed resend beyond what's documented per-endpoint below.

**HTTP status codes used for "this was already handled, don't worry"** — a consistent vocabulary across the endpoints below:
- **200/201 with a "already done" message** — the safest, most common shape: the action is confirmed to have already happened (or was just redundant), no error state to show the user, just display the message.
- **422 with a specific message** — a genuine rejection (validation, a business rule), distinct from a duplicate-submission no-op.
- **429 with a specific message** — "this was just submitted, please wait a moment" — a duplicate submission was caught and blocked. Treat this as informational (maybe a toast: "Already submitting…"), not a hard error requiring the user to fix something.

---

## Per-endpoint safety table

| Endpoint | Double-tap / repeat-call behavior | Notes |
|---|---|---|
| `POST /connections/{platform}/start` (Woo, immediate) | A rapid repeat with identical credentials returns **422** "already in progress." A repeat *minutes/hours* later (past a short server-side lock) returns the **existing connection**, not a duplicate — safe to resubmit the same store's credentials later without fear of creating two rows. | Connecting a genuinely *different* store is never blocked, even seconds later. |
| OAuth callback (Shopify/eBay/Etsy/Amazon/TikTok) | Replaying the exact same callback link/redirect is a safe no-op (won't create a second connection). For **Shopify** specifically, reconnecting the same shop via a brand-new `/start` attempt also safely reuses the existing connection. For **eBay/Etsy/Amazon/TikTok**, a genuinely new `/start` attempt for the same store (not a replay of the same link) can still create a duplicate — those four platforms have no stable store identity available to check against without extra OAuth scope (a known, accepted limitation, not an oversight). | If a merchant reports "I see my store connected twice" on one of these four platforms, that's the known gap — direct them to disconnect the duplicate from Settings, there's no auto-merge. |
| `POST /connections/{id}/sync-now` | A repeat within 60 seconds returns **429** with a "try again in N seconds" message (see `connections-api-reference.md`). | Per-connection cooldown, not per-team — syncing one store never blocks syncing another. |
| `POST /orders/{id}/refund` | A rapid repeat (even truly concurrent, not just sequential) is **safe — only one real refund is ever issued**, returning 200 "already been refunded" on the repeat. | This one matters most: a refund is real money moving on the connected platform, so this is guarded at both the request level and a short internal lock, not just a status check. |
| `POST /orders/{id}/fulfill` / `POST /orders/{id}/cancel` | A repeat returns 200 "already been fulfilled/cancelled," and doesn't call the platform's API again. | These were already close to safe (the platform's own API treats a repeat status-set as a no-op) — this just avoids a wasted API call and a duplicate order-timeline entry. |
| `POST /rules` (create) | A rapid repeat with the **identical** payload (same trigger/conditions/actions/etc.) returns **429** — only one rule is created. Submitting a genuinely different rule right after is never blocked. | Worth calling out specifically: a duplicate *rule* isn't a one-time annoyance like a duplicate message — it keeps firing on every future match, forever, doubling every notification for that trigger until someone notices and deletes it. Disable the "Create rule" button on tap like anything else, but know the server has your back here too. |
| `POST /support/messages` | A rapid repeat with the identical message body returns **429**, only one message is created. | Keyed to the message body — resending a *different* follow-up message immediately after is fine. |
| `POST /assistant/ask` | An identical question for the same conversation within **60s** returns **429** — only one real answer is produced and only one question is debited from the monthly quota. | **The one that costs money on a repeat.** An ask legitimately takes 10+ seconds (up to 5 model tool round-trips), so slow ≠ hung. Never auto-retry on timeout — re-fetch `GET /assistant/conversations/{id}` to see whether the answer already arrived. See `ai-api-reference.md`. |
| `POST /threads/{id}/messages` and `POST /orders/{id}/message` | An identical body to the same thread within **10s** returns **429** and sends nothing. | These reach a **real buyer** (eBay/Etsy/Amazon) or queue a real email (Shopify/Woo). On 429 don't clear the composer or show a failure — the first send succeeded. A genuinely different follow-up message is never blocked. |
| Inbound customer email replies (webhook, no client involvement) | A provider redelivering the same inbound email no longer creates a duplicate message in the thread or a second "New customer message" push. | Nothing to build — noted so a duplicate-looking thread isn't mistaken for a client bug. |
| Admin trial extension (not mobile-facing) | A rapid repeat is blocked with a "wait a moment" message — a double-click no longer grants double the trial days. | Admin panel only, listed for completeness. |
| Admin credit grants (not mobile-facing, but noted for completeness) | Rapid repeats are blocked with a clear "wait a moment" message — a double-click no longer double-grants credits. | Admin panel only, included here since it was part of the same audit. |
| `POST /billing/sync` | Already safe by design — it's a pull-and-overwrite from RevenueCat's own state, so repeat calls converge to the same result regardless of timing. No special handling needed. | — |
| `GET` requests generally | Always safe to retry/refresh — nothing here mutates state. | — |

---

## What to do when a "sync doesn't work" — the actual failure modes

If a store's `last_sync_at` stays `null` well past the expected window (see `connections-flow-screens.md`'s "first sync" note) or a `GET /connections/{id}/health` keeps returning `fix_action: "check_connection"`, that's a server/ops-side issue (the queue worker or scheduler not running — see `deployment.md`), not something the client can resolve by retrying harder. The right client behavior is exactly what `connections-flow-screens.md`'s health screen already specifies: show the message, don't offer a retry button that doesn't exist server-side, and let the "we'll keep retrying automatically" framing be honest — because it is automatic, on the server, not something tapping a button again will speed up.
