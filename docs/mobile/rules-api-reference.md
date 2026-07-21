# StockBeat Mobile — Rules API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

This is the app's core differentiator (Plan §4.4) — Tab 2 in the bottom nav. **Read the "Condition vocabulary" section carefully before building a condition-tree editor** — it documents a real bug that existed until this pass (see the callout below).

---

## ⚠️ Real bug fixed this pass — read before building the condition editor

Rule conditions use a **word-based operator vocabulary** (`"gt"`, `"eq"`, etc.) — **not symbols** (`">"`, `"="`). This wasn't validated server-side until this pass: a condition submitted with the wrong operator format used to save successfully (looked like a normal 201) and then **silently never fire**, since the evaluator treats any unrecognized operator as "never matches" rather than erroring. It's now validated at creation/update time (422 if wrong), so this specific failure mode can't happen anymore — but it's worth knowing about if you're building a picker UI: **use a fixed dropdown of the real operator strings below, never free text or symbol buttons ("+", "−", etc.) that map to symbols client-side.**

---

## Trigger catalogue

`trigger` — one of these 13 values (Plan §4.4 + the AI Assistant's `ai_insight`, added later):

| Trigger | Meaning | Relevant `controls` keys |
|---|---|---|
| `new_order` | Any new order | — |
| `high_value_order` | An order matching your conditions (e.g. total over $X) | — (define the threshold via `conditions`, not `controls`) |
| `unfulfilled_after_x` | An order still unfulfilled after N hours | `threshold_hours` (int, default 24 if omitted) |
| `ship_by_deadline` | Approaching a ship-by deadline | `threshold_hours` (int, "how many hours before deadline to warn") |
| `refund_requested` | A refund was initiated on the platform side | — |
| `order_cancelled` | An order was cancelled on the platform side | — |
| `payment_failed` | A payment failed | — |
| `negative_review` | A new low-rating review came in | `negative_review_max_rating` (int 1–5, "rating at or below this fires") |
| `low_stock` | A product's stock dropped to/below a threshold | `low_stock_threshold` (int) |
| `order_spike` | **Premium only** — order volume anomaly | `spike_count` (int), `spike_window_minutes` (int) |
| `refund_spike` | **Premium only** — refund volume anomaly | `spike_count` (int), `spike_window_minutes` (int) |
| `digest` | A custom recurring summary (distinct from the free-tier daily digest) | `digest_frequency` (`"daily"`\|`"weekly"`), `digest_time` (`"HH:mm"`), `digest_day_of_week` (0–6, Sunday=0, weekly only) |
| `ai_insight` | **Premium only** — an unprompted AI-detected anomaly (Plan §4.12) | none — this one is entirely system-driven, don't build a controls UI for it, just let the merchant enable/disable + pick actions |

**Plan gating you must check client-side before offering these** (read from `GET /me`'s `entitlements.limits`, same keys used elsewhere):
- `order_spike`/`refund_spike` — require `advanced_triggers_enabled: true`
- `ai_insight` — requires `ai_proactive_insights_enabled: true`
- Every other trigger is available from Starter up (Free gets presets only — see below)
- `max_rules` (int\|null) — the create button should be disabled/paywalled once the team is at this count; `null` = unlimited

**Free plan note:** `max_rules` is `0` on Free — the create-rule screen shouldn't be reachable at all on Free, route straight to the upgrade paywall instead of showing an empty form that will 422.

---

## Condition vocabulary

`conditions` shape: `{ "all": [...], "any": [...] }` — a rule matches when **every** `all` condition is true, AND (**at least one** `any` condition is true, OR `any` is empty/omitted). Both keys are optional; omit `conditions` entirely for a trigger that doesn't need any (e.g. plain `new_order`).

Each condition item: `{ "field": "...", "operator": "...", "value": ... }`.

**`field`** — exactly these 10 values, and what UI control each one implies:
| Field | Compares against | Value input |
|---|---|---|
| `channel` | Platform (`shopify`/`woo`/`ebay`/`etsy`/`amazon`/`tiktok`) | Fixed dropdown — same 6 values used everywhere else in this API, never free text |
| `store` | A specific `connection_id` (integer) | Dropdown sourced from `GET /connections` (`connections-api-reference.md`) — show each connection's display `name`, submit its `id`. Never a free-text integer field. |
| `total` | Order total, numeric | Numeric input |
| `sku` | Substring match against any line item's SKU (case-insensitive) | Free text — there's no "list of SKUs used" endpoint to build a picker from |
| `product` | Substring match against any line item's title (case-insensitive) | Free text, same reason |
| `quantity` | Total item quantity across the order, numeric | Numeric input |
| `customer_country` | Order's `shipping_address.country` (`orders-api-reference.md`'s order resource) | **Not a fixed enum** — this is whatever raw value the platform sent (for WooCommerce, the only real adapter today, it's an ISO 3166-1 alpha-2 code like `"AU"`). Build a standard country picker client-side (bundled ISO list, value = alpha-2 code) rather than trying to derive valid values from the API — there's no "list of countries seen" endpoint, and a future non-Woo platform isn't guaranteed to send alpha-2 codes at all. |
| `repeat_buyer` | `true`/`false` — has this customer email ordered before | Boolean toggle, not free text |
| `shipping_method` | Shipping method string from the order's shipping address | **Free text, genuinely unstructured** — this is raw, platform-specific text (e.g. "USPS Priority", "Standard Shipping") with no fixed catalog and no "list of methods seen" endpoint. Don't build a dropdown you can't actually populate correctly; a plain text input (with a note that it must match exactly) is the honest choice here. |
| `tag` | Exact match against one of the order's tags | Free text, or reuse whatever tag-entry UI `orders-feed-screens.md`'s tag editor already has, since these are the same order tags |

**`operator`** — exactly these 8 values, **word-based, not symbols**:
| Operator | Meaning | Value shape |
|---|---|---|
| `eq` | Equals | single value |
| `neq` | Not equals | single value |
| `gt` | Greater than | numeric |
| `gte` | Greater than or equal | numeric |
| `lt` | Less than | numeric |
| `lte` | Less than or equal | numeric |
| `in` | Value is one of a set | array |
| `between` | Numeric range, inclusive | two-item array `[min, max]` |

**Real quirk worth knowing**: for `sku`/`product`/`tag` fields, the operator is accepted but **ignored** — matching is always a substring/exact check regardless of what operator you send (server-side implementation detail, not a client bug). Still send a real operator value from the list above (validation requires it), `eq` is the sensible default for these three fields.

**Example — "eBay orders over $200":**
```json
{ "conditions": { "all": [
  { "field": "channel", "operator": "eq", "value": "ebay" },
  { "field": "total", "operator": "gt", "value": 200 }
] } }
```

---

## `GET /rules`

**Requires auth.**
```json
{ "success": true, "message": null, "data": { "rules": [
  { "id": 1, "name": "High-value order alert", "trigger": "high_value_order", "conditions": {"all": [{"field": "total", "operator": "gte", "value": 200}]}, "actions": [{"type": "push"}], "sound": null, "controls": {"quiet_hours": {"start": "22:00", "end": "08:00", "timezone": "Australia/Sydney"}}, "enabled": true, "created_at": "2026-07-10T00:00:00.000000Z" }
] } }
```
**There's no `DELETE` endpoint** — a rule can only be disabled (`PUT` with `enabled: false`), never removed. Build the UI around that (a toggle + edit, no "delete" swipe action).

## `POST /rules`

**Requires auth**, `owner`/`manager` role.

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `trigger` | required, one of the 13 values above |
| `conditions` | optional, `{all?: [...], any?: [...]}`, each item validated per the vocabulary above |
| `actions` | required, array, min 1 — see below |
| `sound` | optional, one of `default` `cha_ching` `alert` `chime` |
| `controls` | optional, object — see the per-trigger table above, plus `cooldown_minutes` (int) and `quiet_hours: {start: "HH:mm", end: "HH:mm", timezone: "IANA/Zone"}`, both usable on any trigger |
| `enabled` | optional bool, defaults true |

**`actions`** — array of `{type: "..."}`, at least one:
| Type | Extra required field |
|---|---|
| `push` | — |
| `email` | — |
| `sms` | — |
| `notify_member` | `user_id` (integer — a team member's user id, from `GET /team`) |
| `auto_tag` | `tag` (string, max 50 — applied to the order automatically) |

**Success — 201:** `{rule: {...}}`

**Errors:**
| Status | Trigger | Message |
|---|---|---|
| 422 | `max_rules` reached | `errors.trigger[0]` = `"You've reached your plan's custom rule limit ({N}). Upgrade to add more rules."` — paywall trigger |
| 422 | `order_spike`/`refund_spike` without `advanced_triggers_enabled` | `errors.trigger[0]` = `"This trigger requires the Premium plan."` |
| 422 | `ai_insight` without `ai_proactive_insights_enabled` | `errors.trigger[0]` = `"Proactive AI Insights requires the Premium plan."` |
| 422 | Bad condition field/operator | `errors["conditions.all.0.operator"][0]` (or `.field`) — standard nested-key validation error, same pattern as anywhere else in this API |
| 422 | Profile setup incomplete | `"Complete profile setup before creating rules."` |

## `PUT /rules/{id}`

**Requires auth**, `owner`/`manager` role. Same field rules as `POST`, all optional (partial update) except that the same plan-gate checks re-run if you're **changing** `trigger` to a gated one — this closes a real gap: a Starter team can't bypass the Premium gate by creating an allowed-trigger rule and then editing it to `order_spike`.

**Success — 200:** `{rule: {...}}`. **Errors:** 404 if not your team's rule, same 422s as create for anything that fails validation/gating.

## `POST /rules/{id}/test`

**Requires auth**, `owner`/`manager` role. Fires the rule for real right now — **not a dry run**, it really sends the push/email/SMS and really logs an execution. Use this for a "Test this rule" button so the merchant can confirm it works before waiting for it to trigger naturally.

Repeatable — calling it again always works (order-less test fires are exempt from the normal per-order dedup).

```json
{ "success": true, "message": null, "data": { "execution": {
  "id": 10, "order_id": null, "trigger": "high_value_order",
  "actions_result": [{"type": "push", "status": "sent"}], "fired_at": "2026-07-16T02:00:00.000000Z"
} } }
```
`actions_result[].status` varies by action type — real values include `sent`, `quota_exceeded` (email/SMS monthly cap hit), `muted_by_preference`, `quiet_hours`, `insufficient_credit` (SMS), `no_phone_number` (SMS), `missing_user_id`/`skipped_no_order` (misconfigured action). Show these plainly in a test-fire result screen rather than just "success"/"failure" — they're genuinely informative.

## `GET /rules/{id}/executions`

**Requires auth.** Most recent 50 firings, newest first, same shape as the `test` response's `execution` object. `order_id` is `null` for order-less triggers (`digest`, `low_stock`, `negative_review`, `ai_insight`).

---

## Building a rule from plain English — the AI Rule Builder (Pro+)

`POST /assistant/rule-draft {prompt}` (documented in the AI Assistant reference, not repeated here) turns a sentence like "notify me by text whenever an eBay order is over $200" into a structured draft in this exact same shape — **use it as an alternative input method on the same create-rule screen**, not a separate flow: show the parsed draft pre-filled into the normal form fields, let the merchant review/edit, then submit through the ordinary `POST /rules` above. The draft endpoint never saves anything itself.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success (update, test-fire, executions list) |
| 201 | Success (create) |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Rule doesn't exist or isn't yours |
| 422 | Validation failure, plan/trigger gate, or unrecognized condition field/operator |
