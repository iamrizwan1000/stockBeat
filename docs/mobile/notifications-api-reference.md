# StockBeat Mobile — Notification Center & Announcements API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Two small, cross-cutting surfaces, not a bottom-nav tab — both are usually a bell/banner accessible from anywhere in the app shell, same category as `ai-api-reference.md`'s entry points. `GET /config` (the pre-login launch gate) is documented in `auth-api-reference.md`, not here — that one's unauthenticated and belongs to the launch sequence, not this in-app surface.

---

## Notification Center (the bell icon)

This is the **in-app record of everything that's been sent to this user** — distinct from an actual push arriving on the device. A push can fail to deliver (muted, quiet hours, no devices — `settings-api-reference.md`; or its store connection is muted — `connections-api-reference.md`'s `notifications_muted`, added 2026-07-23) while still being logged here; conversely, **not everything that reaches the device has a row here** (see the SMS gap below). Treat this screen as "history of what fired for me," not "proof of what I actually received."

### `GET /notifications`

**Requires auth.** Most recent 50, newest first — no pagination, no unread-only filter param (filter client-side on `read_at === null` if you want an unread list).

```json
{ "success": true, "message": null, "data": { "notifications": [
  {
    "id": 1, "type": "rule_push", "title": "High-value order",
    "body": "Order #1042 — $84.00", "data": { "order_id": "1", "trigger": "high_value_order", "platform": "shopify" },
    "read_at": null, "created_at": "2026-07-16T01:00:00.000000Z"
  }
] } }
```

### `type` and `data` — what each one means and where tapping it should go

| `type` | When it's created | `data` shape | Tap navigates to |
|---|---|---|---|
| `rule_push` | A rule fired and delivered (or attempted) push | `{trigger: "..."}` **always** (added 2026-07-24 — see below), plus `order_id: "123"` if the trigger is order-scoped, plus `platform: "shopify"` **if** the firing resolved to a real store (order-scoped triggers, plus `low_stock`/`negative_review`) | Order detail if `order_id` present, otherwise the Rules tab (there's no `rule_id` in the payload — you can't deep-link to "which rule fired," only to the order if there is one) |
| `rule_email` | A rule fired and delivered (or attempted) email | Same as `rule_push` minus `order_id` — email never carries it | No sensible deep link; tapping can just mark it read, or go to Feed |
| `digest` | The **free-tier** daily/weekly digest sent (`SendMorningDigestAction`) | Always `{}` | Feed tab (the digest is a summary, not tied to one order) |
| `admin_broadcast` | An admin-sent broadcast (push or in-app banner channel only — see below) | `{broadcast_id: 5}` | No merchant-facing screen shows a single broadcast by id — treat as informational only, no navigation |
| `support_reply` | Staff replied in your support chat | `{thread_id: 3}` | `SupportChatScreen` (`settings-flow-screens.md`) — **this `thread_id` is a support thread, not an inbox thread** (see the warning below) |
| `trial_reminder` | Day 3/10 trial win-back (Plan §6.3) | `{trial_days_remaining: "4"}` | Subscription screen (`settings-flow-screens.md`) |
| `inbox_message` | A new customer message arrived (eBay member message, inbound email reply) | `{thread_id: 7}` | `ThreadDetailScreen` (`inbox-flow-screens.md`) — **a different `thread_id` namespace than `support_reply`'s** |

### `data.trigger` and `data.platform` — "where did this alert come from" (added 2026-07-24)

Every `rule_push`/`rule_email` row now carries `data.trigger` — the rule's trigger key verbatim from the 13-value catalogue in `rules-api-reference.md` (`"new_order"`, `"low_stock"`, `"ai_insight"`, `"digest"` — yes, a **Pro custom digest rule** produces `type: "rule_push"`/`"rule_email"` with `trigger: "digest"`, a genuinely different thing from the free-tier `type: "digest"` row above; don't conflate the two). Use this to render a badge/label per row (e.g. "AI Insight", "Low stock", "Ship-by deadline") instead of guessing from the body text.

`data.platform` is present **only** when the firing resolved to a real store connection — one of `shopify`/`woo`/`ebay`/`etsy`/`amazon`/`tiktok` (`connections-api-reference.md`'s `platform` values). This covers every order-scoped trigger plus `low_stock`/`negative_review` and the Free-tier preset new-order push. It's **absent** for `digest` and `ai_insight` triggers, since both summarize across every connected store at once — there's no single platform to attribute them to; show a generic "AI"/team-wide treatment for those rather than a blank or placeholder platform badge.

Both fields are **added directly to the FCM push data payload too** (for push), not just the REST response — so a native tap handler reading the OS push payload has them available without an extra `GET /notifications` round-trip.

### ⚠️ `thread_id` means two different things depending on `type`

`support_reply` and `inbox_message` both carry a field literally named `data.thread_id` — **but they point at different tables** (`SupportThread` vs `InboxThread`) with completely different detail screens. **Always branch on `type` first**, never route based on the presence of `data.thread_id` alone, or a support reply notification will try to open the wrong screen (and vice versa).

### ⚠️ SMS-delivered rule actions never appear here

A rule action of type `sms` (`rules-api-reference.md`) is sent directly via Twilio and **never creates a Notification Center row** — there's no `TYPE_RULE_SMS` entry actually written anywhere in the backend today, only push/email/digest/broadcast/support-reply/inbox-message do. Don't build an SMS-specific empty-state or filter expecting rows that will never exist; if a merchant asks "why don't I see my text alerts in the notification list," the honest answer is that only the SMS itself (on their phone, outside this app) is the record — there's nothing to show here.

### `POST /notifications/read`

**Requires auth.**
```json
{ "ids": [1, 2, 3] }
```
`ids`: optional array of integers. **Omit `ids` entirely (or send `{}`) to mark every unread notification as read** — this isn't a no-op, it's "mark all as read," so don't send an empty body by accident on a single-item tap.

**Success — 200:**
```json
{ "success": true, "message": null, "data": { "marked_read": 3 } }
```
`marked_read` is the count actually updated (already-read ids in your list don't double-count or error — safe to call idempotently).

**Typical usage:** call with a single-item `ids` array when the user taps one notification (mark just that one read, then navigate per the table above); call with `ids` omitted for an explicit "Mark all as read" button.

---

## In-app announcements (banners)

Admin-authored banners (Plan §8.7.5-adjacent), audience-targeted, **not the same thing as the Notification Center** — these are more like a dismissible "what's new" strip, not a per-user activity log.

### `GET /announcements`

**Requires auth.** Returns only announcements currently within their `starts_at`/`ends_at` window **and** matching this specific user's audience rules (plan, platform, etc. — resolved server-side, nothing for the client to filter further).

```json
{ "success": true, "message": null, "data": { "announcements": [
  { "id": 1, "title": "New: order-spike alerts", "body": "Premium now includes order and refund spike alerts.", "dismissible": true }
] } }
```

### `POST /announcements/{id}/dismiss`

**Requires auth.** No body. Real, server-persisted dismissal (added 2026-07-22 — previously there was no dismiss endpoint at all, only a client-side-only workaround). `dismissible: true` is a display hint (show a close/X control) — dismissing calls this endpoint, not a local-only flag.

```json
{ "success": true, "message": "Announcement dismissed.", "data": null }
```

**Per-user, not per-device or global** — dismissing on one device dismisses it everywhere that user is logged in (their next `GET /announcements` on any device won't include it), but every *other* user targeted by the same announcement still sees it until they dismiss it themselves too. Idempotent — dismissing twice is a normal 200, not an error, so don't guard against double-tapping.

**422** if the announcement's `dismissible` is `false` — don't render a close control for those (see the flow doc), but if you ever do call this on a non-dismissible one by mistake, it fails cleanly rather than silently succeeding. **404** if the id doesn't exist.

**Where to show these:** a small banner strip at the top of the Feed tab is the natural placement (consistent with "what's new" banners elsewhere) — call `GET /announcements` once per app foreground/session rather than polling, these change rarely.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Announcement doesn't exist (dismiss only) |
| 422 | `ids.*` not an integer (malformed mark-read request), or dismissing a non-dismissible announcement |
