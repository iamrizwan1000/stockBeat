# StockBeat Mobile — Inbox API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Tab 3 in the bottom nav (Plan §4.5/§4.10). **Requires the Pro plan or higher** (`entitlements.limits.inbox_enabled`) — Free/Starter shouldn't see this tab at all, route straight to the upgrade paywall if a Starter user somehow reaches it.

## The important thing to understand before building this

There is **no "start a new conversation" endpoint.** A thread only ever comes into existence two ways:
1. **Outbound, from an order** — `POST /orders/{id}/message` (documented in `orders-api-reference.md`) gets-or-creates the thread for that order and sends the first message in one call.
2. **Inbound** — a real customer message arrives via webhook/poll (eBay member messages, inbound email replies) and a thread is created server-side automatically.

So this tab is a **read-and-reply surface over threads that already exist**, not a compose-a-new-message screen. Don't build a "+ New conversation" button here — the entry point for a *new* conversation is always the order detail screen's "Message customer" button.

---

## `GET /threads`

**Requires auth.** `?assigned_to={user_id}` optional filter.

```json
{ "success": true, "message": null, "data": { "threads": [
  { "id": 1, "channel": "woo", "connection_id": 1, "customer_name": "Alex Chen", "customer_email": "alex@example.com", "order_id": 1, "order_number": "#1042", "assigned_to": null, "last_message_at": "2026-07-17T01:00:00.000000Z" }
] } }
```

`order_id`/`order_number` can both be `null` — a **pre-sale eBay message** (a buyer asking a question before ordering) creates a thread with no linked order yet. Handle this in the UI: no "view order" link, no order-context panel, just the conversation.

`channel` is the platform key (`shopify`/`woo`/`ebay`/`etsy`/`amazon`/`tiktok`) — **use `connection_id` (not `channel`) to look up messaging capabilities** from `GET /connections` (`connections-api-reference.md`), in case a team ever has two connections on the same platform.

## `GET /threads/{id}/messages`

**Requires auth.**
```json
{ "success": true, "message": null, "data": { "messages": [
  { "id": 1, "direction": "in", "body": "Where's my order?", "status": "delivered", "failure_reason": null, "created_at": "2026-07-17T01:00:00.000000Z" },
  { "id": 2, "direction": "out", "body": "It shipped yesterday!", "status": "sent", "failure_reason": null, "created_at": "2026-07-17T01:05:00.000000Z" }
] } }
```
`direction`: `"in"` (from the customer) or `"out"` (from your team) — this is your left/right bubble alignment.

`status` (only meaningful for `direction: "out"` — always render `"in"` messages as just delivered):
| Status | Meaning |
|---|---|
| `queued` | Persisted, send in progress — show a subtle "sending" indicator |
| `sent` | Delivered to the outbound channel (email queued, or the platform API call succeeded) |
| `delivered` | Platform confirmed receipt (not every channel reports this — absence doesn't mean it failed) |
| `failed` | Did not go out — show `failure_reason` to the merchant, don't just hide the message |

**No polling/pagination documented here** — this returns the full thread history in one call, oldest first. Fine for now given real conversation volumes; revisit if a thread ever gets pathologically long.

## `POST /threads/{id}/messages`

**Requires auth**, `owner`/`manager` role.

Two mutually exclusive ways to send:
```json
{ "body": "It shipped yesterday!" }
```
or
```json
{ "reply_template_id": 3 }
```
`body`: required unless `reply_template_id` is present, max 4000 chars. `reply_template_id`: must belong to your team (validated server-side — a template id from another team 422s as if it doesn't exist).

**Success — 201:** `{message: {...}}` in the same shape as the messages list, `direction: "out"`. **This can come back with `status: "failed"`** even on a 201 — a 201 means "we recorded your send attempt," not "it definitely went out." Always check the returned message's `status`/`failure_reason`, don't assume success from the HTTP status alone.

**Common real failure reasons** (`failure_reason` text, not an enum — show it verbatim):
- `"This thread has no customer email on file."` (Shopify/Woo threads, email channel)
- Etsy: a message from the platform's own `AdapterNotReadyException` when conversations approval hasn't been granted yet — show as "Etsy messaging isn't available for this shop yet," not a generic error

## `POST /threads/{id}/assign`

**Requires auth**, `owner`/`manager` role.
```json
{ "user_id": 3 }
```
Omit `user_id` (or send it as absent) to **unassign**. **Success — 200:** returns the updated `thread`.

---

## Reply templates

Team-wide saved snippets with `{customer_name}`/`{order_number}`/`{tracking}` variable substitution (rendered server-side — you never need to interpolate these client-side, just send `reply_template_id` and the server fills them in from the thread's linked order).

### `GET /reply-templates`
```json
{ "templates": [ { "id": 1, "name": "Shipped", "body_with_variables": "Hi {customer_name}, order {order_number} shipped! Tracking: {tracking}" } ] }
```

### `POST /reply-templates`
```json
{ "name": "Shipped", "body_with_variables": "Hi {customer_name}, order {order_number} shipped! Tracking: {tracking}" }
```
Both required, `name` max 255, `body_with_variables` max 4000. **201**, returns `{template: {...}}`.

### `PUT /reply-templates/{id}`
Same body shape, both fields required (this endpoint doesn't support partial update — send both even if only changing one). **200**.

### `DELETE /reply-templates/{id}`
**200**, `{success: true, message: null, data: null}`.

**Variable behavior worth knowing:** if a thread has no linked order (pre-sale message, or an order-linked thread whose order was somehow removed), `{order_number}` and `{tracking}` render as **empty strings**, not left as literal `{order_number}` text and not omitted — a template built assuming an order will show gaps. If you're building a template editor, warn against using order-specific variables in a "general" template meant for pre-sale questions.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 201 | Message/template created — **for messages, still check the returned `status` field, 201 isn't proof of delivery** |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Thread/template doesn't exist or isn't yours |
| 422 | Validation failure (missing body, template from another team, etc.) |
