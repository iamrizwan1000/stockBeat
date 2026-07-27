# StockBeat Mobile — Orders / Feed API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

This is what renders on the Feed — the main screen, reached once at least one store is connected (`connections-api-reference.md`). Includes the analytics summary shown in the Feed header (Plan §4.10: "Analytics lives on the Feed header as today's numbers").

---

## `GET /orders`

**Requires auth.** Cursor-paginated, filterable, globally searchable.

**Query params (all optional):**
| Param | Type | Notes |
|---|---|---|
| `channel` | string | One of `shopify` `woo` `ebay` `etsy` `amazon` `tiktok` |
| `store` | integer | A specific `connection_id` |
| `status` | string | One of `new` `unfulfilled` `shipped` `refunded` `cancelled` — **note there's no `processing`/`paid` etc.** here, this is the *order* status, not payment/fulfillment status (see the resource shape below for those two, which are separate fields) |
| `date_from` / `date_to` | date | Filters on `placed_at` |
| `value_min` / `value_max` | numeric | Filters on `total` (the order's own currency, not `total_base_currency`) |
| `tag` | string | Exact match against one tag |
| `q` | string | Free-text search across order number, customer name/email, item SKU/title — **fuzzy, can partial-match**, not what a customer-detail screen should use (see `customer_email` below) |
| `customer_email` | string | **Added 2026-07-26 (Plan §4.16), exact match only** — the way to build a "this customer's full order history" screen: `GET /orders?customer_email=alex@example.com` returns only that exact address's orders, with the same pagination/`history_days` gating as every other call to this endpoint. Don't use `q` for this — a fuzzy search can pull in an unrelated order that happens to partial-match somewhere else in its text fields. Pair with `GET /customers` (`customers-api-reference.md`) for the list of customers to tap into in the first place. |
| `include_snoozed` | boolean | Default false — a snoozed order (see `snoozed_until`) is hidden from the default feed until it expires |
| `cursor` | string | From the previous response's `next_cursor` — **this is not a page number**, it's an opaque token. Append it verbatim; don't try to construct or decode it client-side |

**`history_days` is enforced server-side** (Plan §5, per the team's plan) — `date_from` earlier than the plan's window simply won't return anything beyond that boundary, there's no error, the results are just silently bounded. Don't build client-side date-range pickers that let the user pick further back than their plan allows without at least a hint.

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "orders": [
      {
        "id": 1,
        "platform": "woo",
        "connection_id": 1,
        "order_number": "#1042",
        "status": "unfulfilled",
        "fulfillment_status": "unfulfilled",
        "payment_status": "paid",
        "currency": "AUD",
        "total": 84.00,
        "discount_amount": 5.00,
        "tax": 4.50,
        "total_base_currency": 84.00,
        "customer_name": "Alex Chen",
        "customer_email": "alex@example.com",
        "shipping_address": { "line1": "1 Example St", "city": "Sydney", "postcode": "2000", "country": "AU" },
        "placed_at": "2026-07-16T00:30:00.000000Z",
        "ship_by_at": "2026-07-18T00:30:00.000000Z",
        "ship_by_hours_remaining": 46.5,
        "is_ship_by_urgent": false,
        "tags": ["gift"],
        "is_test": false,
        "snoozed_until": null
      }
    ],
    "next_cursor": "eyJpZCI6MSwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ"
  }
}
```
`next_cursor` is `null` on the last page — that's your "no more pages" signal, not an empty `orders` array (the last page can still have items).

**Field notes:**
| Field | Notes |
|---|---|
| `status` vs `fulfillment_status` vs `payment_status` | Three independent dimensions. `status` is the overall lifecycle (`new`→`unfulfilled`→`shipped`, or `refunded`/`cancelled`). `fulfillment_status` is `unfulfilled`\|`partial`\|`fulfilled`. `payment_status` is `pending`\|`paid`\|`partially_refunded`\|`refunded`\|`failed`. Don't assume `status: shipped` implies `payment_status: paid` — check both independently for badge logic. |
| `discount_amount` / `tax` | **Only populated for WooCommerce today** — every other platform's adapter can't connect yet (Amazon) or doesn't map these fields yet, so expect `null` and show nothing rather than "$0.00" (a real `null` is "we don't know," not "there was none") |
| `total_base_currency` | The team owner's reporting currency equivalent. Can be `null` even for a real order if no FX rate exists yet for that currency pair/date — again, `null` means "unknown," don't render `$0.00` |
| `ship_by_hours_remaining` | Negative once overdue — a real, meaningful signal, don't clamp to zero. `is_ship_by_urgent` is `true` at ≤24h remaining (already computed server-side, don't reimplement the threshold client-side) |
| `is_test` | Always `false` in this endpoint's results — test orders are excluded from `GET /orders` entirely by default, this field is really just here for the (rare) case you're looking at order data elsewhere |

**Errors:** empty `{orders: [], next_cursor: null}` (not an error) if the team has no orders yet, or if `needs_profile_setup` is still true — build the empty state around that, not a 4xx.

---

## `GET /orders/{id}`

**Requires auth.** Same shape as a list item, plus `items`, `notes`, and (added 2026-07-27, Plan §4.19) a chronological `events` timeline.

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "order": {
      "...": "all list fields, plus:",
      "items": [
        { "id": 1, "sku": "VNT-014", "title": "Vintage Denim Jacket", "image_url": "https://example.com/img/vnt-014.jpg", "qty": 1, "price": 84.00 }
      ],
      "notes": [
        { "id": 1, "body": "Customer asked to hold for pickup.", "user_id": 3, "created_at": "2026-07-16T02:00:00.000000Z" }
      ],
      "events": [
        { "id": 1, "type": "created", "payload": null, "occurred_at": "2026-07-16T00:30:00.000000Z" },
        { "id": 2, "type": "tags_updated", "payload": { "tags": ["gift"] }, "occurred_at": "2026-07-16T01:00:00.000000Z" },
        { "id": 3, "type": "fulfilled", "payload": { "tracking_number": "1Z999AA10123456784", "carrier": "UPS" }, "occurred_at": "2026-07-17T09:00:00.000000Z" }
      ]
    }
  }
}
```

**`events` field notes:**
- **Always oldest-first** (true chronological order, not newest-first like the notification center) — render as a top-to-bottom history, not a feed.
- **`type`** is one of `created`, `updated` (both ingestion-sourced), `fulfilled`, `refunded`, `cancelled`, `snoozed`, `tags_updated`, `note_added` — build a fixed icon/label map from these, don't guess from `payload`'s shape.
- **`payload`** varies by `type` and can be `null` (e.g. `created`/`updated` never populate one today). Don't assume any specific key exists without checking for `type` first.
- Only written on **real success** — a failed fulfill/refund/cancel attempt (e.g. an unsupported-capability 422) writes nothing here, so this is a true "what actually happened" log, never a log of attempts.
- A bulk-cancel or bulk-tag call (below) writes one event per affected order automatically — no separate call needed to see it reflected here.

**Errors:** `404` if the order isn't in the caller's team, **or if the caller's `store_visibility` restricts them from this order's connection** (Plan §4.7 — a Viewer/Agent limited to specific stores gets a 404, not a 403, for orders outside their allowed stores; same not-a-403 pattern used throughout this API to avoid confirming existence).

---

## Quick actions

All of these: **requires auth**, `owner`/`manager` role only, and only reachable on an order whose connection you can see (same 404 rule as above).

### `POST /orders/{id}/notes`
```json
{ "body": "Customer asked to hold for pickup." }
```
`body`: required, max 2000 chars. **201** on success, returns `{note: {...}}`. Notes are append-only — no edit/delete endpoint exists.

### `POST /orders/{id}/tags`
```json
{ "tags": ["gift", "priority"] }
```
`tags`: required array, each item a string max 50 chars. **This replaces the entire tag list**, it's not an add-one operation — if you're building an "add a tag" chip UI, read the order's current `tags` first and include them all in the request. **200**, returns the updated `order`.

### `POST /orders/{id}/snooze`
```json
{ "until": "2026-07-18T00:00:00Z" }
```
`until`: present-but-nullable, must be a future date if given. **Send `null` explicitly to un-snooze** (the field must be present in the request either way — omitting it entirely is a validation error, not a no-op). **200**, returns the updated `order`. A snoozed order drops out of the default `GET /orders` feed until `until` passes, unless the caller passes `include_snoozed=true`.

### `POST /orders/{id}/fulfill`
```json
{ "tracking_number": "1Z999AA10123456784", "carrier": "UPS" }
```
`tracking_number`: required. `carrier`: optional free text (not an enum — platforms don't standardize this).

- **200 success:** `{order: {...}}`, `message: "Order marked as fulfilled."` — this is a **real call through to the platform's own API** (WooCommerce today), not just a local status flip. Show a loading state, this isn't instant.
- **422 — platform doesn't support this here:** `errors.order[0]` = `"This channel doesn't support marking orders fulfilled from here."` — check `capabilities.fulfill_tracking` from the connection (`connections-api-reference.md`) *before* even showing this button, this error is the server-side backstop, not the primary UX.

### `POST /orders/{id}/refund`
```json
{ "amount": 20.00, "reason": "Item damaged in transit" }
```
`amount`: optional — **omit entirely for a full refund**, don't send the order total yourself. `reason`: optional, max 500 chars.

- **200 success:** `{order: {...}}`, `message: "Order refunded."`
- **422 — not supported:** same pattern as fulfill, `errors.order[0]` = `"This channel doesn't support refunds from here."`
- **422 — amount too high:** `errors.amount[0]` = `"The refund amount can't exceed the order total."` — also worth a client-side max-value check on the input before submit, to avoid a round trip for an obvious mistake

### `POST /orders/{id}/cancel`
```json
{ "reason": "Out of stock" }
```
`reason`: optional, max 500 chars.

- **200 success:** `{order: {...}}`, `message: "Order cancelled."`
- **422 — not supported:** `errors.order[0]` = `"This channel doesn't support cancelling orders from here."`

**Important correction vs. what you might see in the auto-generated OpenAPI docs (`docs/api/openapi.yaml`):** those show a `200` response for the "not supported" scenario on fulfill/refund/cancel — that annotation is stale. The real, verified behavior (confirmed by calling the action directly) is **422**, with the message under `errors.order[0]`, same as any other validation failure. Handle it as a normal 422, not a `200` with `success: false`.

### `GET /orders/{id}/packing-slip`
Returns a rendered **PDF directly** (`Content-Type: application/pdf`), not a JSON envelope — download/open it with whatever your HTTP client uses for binary responses, then hand off to the native share sheet. No request body. Deliberately **omits price** — this is a warehouse packing document, not something to hand a customer as proof of what they paid.

### `GET /orders/{id}/invoice` — added 2026-07-26 (Plan §4.18)
The priced counterpart to the packing slip above — same mechanism (rendered **PDF directly**, `Content-Type: application/pdf`, hand off to the native share sheet), different content: item unit price × qty, subtotal, `discount_amount`/`tax` when known (omitted from the PDF when `null`, never shown as `$0.00`), and total. **No role gate** — any team member can fetch it (it's a read-only document, not a mutating action) — and **free on every plan**, unlike bulk order actions below.

---

## Bulk order actions — added 2026-07-26 (Plan §4.17)

Both require auth, `owner`/`manager` role, **and `plan_limits.bulk_actions_enabled` (Starter and up — not Free)**. Check `bulk_actions_enabled` from `/me`'s entitlements (`billing-api-reference.md`) before showing a bulk-select UI at all; the server also enforces it, returning:
```json
{ "success": false, "message": "Bulk order actions require the Starter plan or higher.", "errors": null }
```
with a **403**, distinct from validation errors below.

Both endpoints check that **every id in `ids` belongs to your team atomically before doing anything** — if even one id is someone else's order, the whole call 422s and nothing is applied:
```json
{ "success": false, "message": "The given data was invalid.", "errors": { "ids": ["One or more orders do not belong to your team."] } }
```

### `POST /orders/bulk-cancel`
```json
{ "ids": [101, 102, 103], "reason": "Batch cleanup" }
```
`ids`: required array, 1–500 integers. `reason`: optional, max 500 chars, applied to every order in the batch.

**Important: per-order success is *not* atomic**, unlike the ownership check above — cancelling can genuinely fail per-order (a channel without cancel support, a platform rejection) for reasons outside your control, so one bad order in a batch of 20 doesn't block the other 19. **200 success:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "results": [
      { "id": 101, "success": true, "error": null },
      { "id": 102, "success": false, "error": "This channel doesn't support cancelling orders from here." }
    ]
  }
}
```
Render this as a "18 of 20 cancelled" summary with per-row error detail, not a single pass/fail toast — a 200 here does not mean every order was actually cancelled, check each `results[i].success`.

### `POST /orders/bulk-tag`
```json
{ "ids": [101, 102, 103], "tag": "urgent" }
```
`ids`: required array, 1–500 integers. `tag`: required string, max 50 chars.

**Appends `tag` to each order's existing tags (deduped)** — this is deliberately different from the single-order `POST /orders/{id}/tags`, which *replaces* the whole list. Bulk-tag never fails per-order once the ownership check above passes (it's a plain field update, no platform call), so there's no per-id result to check — every id in `ids` always succeeds. **200 success:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "orders": [
      { "id": 101, "order_number": "#1042", "tags": ["gift", "urgent"] }
    ]
  }
}
```

### `POST /orders/bulk-packing-slips` — added 2026-07-27 (Plan §4.22)
```json
{ "ids": [101, 102, 103] }
```
`ids`: required array, **1–100** integers (lower cap than the other two bulk endpoints — PDF render time scales with page count). Requires `bulk_actions_enabled` (Starter+) and the same atomic team-ownership check as above — but **no `owner`/`manager` role gate**, since this is read-only, matching the single-order packing-slip/invoice endpoints. Returns **one multi-page PDF directly** (`Content-Type: application/pdf`), one order per page in the order you requested them — not a JSON envelope, not a zip. Hand it straight to the native share sheet, same as the single-order packing slip. No per-order failure mode — every id that passes the ownership check always renders.

---

## `POST /orders/{id}/message` — contacting the customer

**Requires auth**, `owner`/`manager` role. Gets-or-creates the order-linked inbox thread and sends the first (or next) message in one call — this is the entry point into the separate **Unified Inbox** module; full thread list, reply templates, assignment, etc. are covered in `inbox-api-reference.md`, not here. A plain "Message customer" button on the order detail screen calling this endpoint is all this doc needs to support.

Two mutually exclusive ways to send, same as `inbox-api-reference.md`'s `POST /threads/{id}/messages` (this is the same underlying action):
```json
{ "body": "It shipped yesterday!" }
```
or
```json
{ "reply_template_id": 3 }
```
`body`: required unless `reply_template_id` is present, max 4000 chars. `reply_template_id`: must belong to your team (a template id from another team 422s as if it doesn't exist).

**Success — 201:** `{message: {...}}`, same shape as `inbox-api-reference.md`'s message objects (`direction: "out"`). **A 201 isn't proof of delivery** — check the returned message's `status`/`failure_reason` (e.g. `"This thread has no customer email on file."` for Shopify/Woo threads), same caveat as the Inbox tab's send endpoint.

---

## Favorite/saved filters — added 2026-07-27 (Plan §4.23)

A named, team-shared preset over `GET /orders`'s own filter params — nothing new to learn, this just persists a combination of the params documented at the top of this doc so a seller doesn't have to re-enter it every time. **Free on every plan.**

### `GET /order-filters`
**Requires auth.** Any team member can list — not gated to owner/manager.
```json
{ "success": true, "message": null, "data": { "filters": [
  { "id": 1, "name": "Unfulfilled Shopify", "filters": { "channel": "shopify", "status": "unfulfilled" }, "created_at": "2026-07-27T00:00:00.000000Z" }
] } }
```

### `POST /order-filters`
**Requires auth**, `owner`/`manager` role.
```json
{ "name": "High-value Etsy", "filters": { "channel": "etsy", "value_min": 100 } }
```
`name`: required, max 255 chars. `filters`: required, non-empty object — **only the same keys `GET /orders` accepts** (`channel`, `store`, `status`, `date_from`, `date_to`, `value_min`, `value_max`, `tag`, `q`, `customer_email`, `include_snoozed`); any other key is silently dropped, not stored, not an error. **Success — 201:** `{filter: {...}}`.

### `PUT /order-filters/{id}` / `DELETE /order-filters/{id}`
**Requires auth**, `owner`/`manager` role. Same body shape as create for `PUT`. 404 if the filter isn't your team's.

**Using a saved filter:** read its `filters` object and spread those same keys straight into your next `GET /orders` call — there's no special "apply" endpoint, a saved filter is just a shortcut to filling in the existing filter bar.

---

## Feed header analytics — see `analytics-api-reference.md`

`GET /analytics/summary?range=today` powers the Feed header's "Today: $240.00 · 3 orders" line (with `range=7d`/`30d` available at higher plan tiers). That endpoint, its sibling `GET /analytics/products`, and the deeper `BusinessOverviewScreen` they also power (`business-overview-flow-screens.md`) are a **separate reference doc**, not covered here — see `analytics-api-reference.md` for the full shape, plan gating, and multi-currency (`revenue_base`) semantics.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success — for bulk-cancel specifically, check each `results[i].success`, a 200 does not mean every order succeeded |
| 401 | Missing/invalid/revoked bearer token |
| 403 | Bulk order actions (incl. bulk packing slips) on a Free-plan team (`bulk_actions_enabled` is Starter+ only) |
| 404 | Order or saved filter doesn't exist, isn't yours, or is outside your `store_visibility` |
| 422 | Validation failure, unsupported platform capability, a disallowed analytics range, or (bulk endpoints only) an id in `ids` that doesn't belong to your team |
