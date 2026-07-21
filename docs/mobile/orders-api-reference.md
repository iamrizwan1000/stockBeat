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
| `q` | string | Free-text search across order number, customer name/email, item SKU/title |
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

**Requires auth.** Same shape as a list item, plus `items` and `notes`.

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
      ]
    }
  }
}
```

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
Returns a rendered **PDF directly** (`Content-Type: application/pdf`), not a JSON envelope — download/open it with whatever your HTTP client uses for binary responses, then hand off to the native share sheet. No request body.

---

## Contacting the customer

`POST /orders/{id}/message` exists (gets-or-creates an order-linked inbox thread and sends a message) but is really the entry point into the separate **Unified Inbox** module (Plan §4.5) — thread list, reply templates, assignment, etc. Not covered in this doc; a plain "Message customer" button on the order detail screen calling this endpoint is reasonable to build now, but hold off on a full inbox screen until that module gets its own reference doc.

---

## `GET /analytics/summary` — the Feed header numbers

**Requires auth.** `?range=today|7d|30d` (required).

**Success — 200 (Free/Starter — `range=today` or `7d` only):**
```json
{
  "success": true,
  "message": null,
  "data": {
    "range": "today",
    "total": { "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 },
    "by_channel": [
      { "connection_id": 1, "platform": "woo", "name": "Rivera Vintage Co", "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 }
    ]
  }
}
```

**Success — 200 (Pro/Premium — `analytics_level: "full"`, adds `comparison` + `goal`):**
```json
{
  "range": "7d",
  "total": { "revenue": 1840.0, "revenue_base": 1840.0, "orders_count": 23, "aov": 80.0 },
  "by_channel": [ "..." ],
  "comparison": { "previous_period_revenue": 1500.0, "change_pct": 22.7 },
  "goal": { "current_month_revenue": 4200.0, "best_month_revenue": 6100.0, "pct_of_best_month": 68.9 }
}
```
**Only request a `range` your plan allows** — check `entitlements.limits.analytics_level` from `GET /me` (`"today"` allows only `range=today`; `"7d"` allows `today`/`7d`; `"full"` allows all three). Requesting a disallowed range 422s with `errors.range[0]` = `"Upgrade your plan for more analytics history."` — this is your paywall trigger, don't just show a raw error, open the upgrade sheet.

`comparison`/`goal` keys are **entirely absent** (not `null`) on Free/Starter — check with an existence check, not a null check, when deciding whether to render that part of the header.

## `GET /analytics/products` — top products

Same `range` param and plan gating as above.
```json
{ "products": [ { "sku": "VNT-014", "title": "Vintage Denim Jacket", "units": 12, "revenue": 1008.0 } ] }
```

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Order doesn't exist, isn't yours, or is outside your `store_visibility` |
| 422 | Validation failure, unsupported platform capability, or a disallowed analytics range |
