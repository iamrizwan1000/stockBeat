# StockBeat Mobile — Rules API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

This is the app's core differentiator (Plan §4.4) — Tab 2 in the bottom nav. **Read the "Condition vocabulary" section carefully before building a condition-tree editor** — it documents a real bug that existed until this pass (see the callout below).

---

## ⚠️ Real bug fixed this pass — read before building the condition editor

Rule conditions use a **word-based operator vocabulary** (`"gt"`, `"eq"`, etc.) — **not symbols** (`">"`, `"="`). This wasn't validated server-side until this pass: a condition submitted with the wrong operator format used to save successfully (looked like a normal 201) and then **silently never fire**, since the evaluator treats any unrecognized operator as "never matches" rather than erroring. It's now validated at creation/update time (422 if wrong), so this specific failure mode can't happen anymore — but it's worth knowing about if you're building a picker UI: **use a fixed dropdown of the real operator strings below, never free text or symbol buttons ("+", "−", etc.) that map to symbols client-side.**

---

## Trigger catalogue

`trigger` — one of these 16 values (Plan §4.4 + the AI Assistant's `ai_insight`, plus `positive_review`/`stale_inventory` added 2026-07-27 and `back_in_stock` added 2026-07-28):

| Trigger | Meaning | Relevant `controls` keys |
|---|---|---|
| `new_order` | Any new order | — |
| `high_value_order` | An order matching your conditions (e.g. total over $X) | — (define the threshold via `conditions`, not `controls`) |
| `unfulfilled_after_x` | An order still unfulfilled after N hours | `threshold_hours` (int, default 24 if omitted) |
| `ship_by_deadline` | Approaching a ship-by deadline | `threshold_hours` (int, "how many hours before deadline to warn") |
| `refund_requested` | A refund was initiated on the platform side | — |
| `order_cancelled` | An order was cancelled on the platform side | — |
| `payment_failed` | A payment failed | — |
| `negative_review` | A new low-rating review came in | `negative_review_max_rating` (int 1–5, "rating at or below this fires"), `review_keyword` (optional string — see note below) |
| `positive_review` | **Added 2026-07-27, WooCommerce-only in practice** — a new high-rating review came in. eBay's feedback poller only ever fetches *negative* feedback from eBay's API, so this trigger has nothing to check for an eBay connection — don't advertise it as available there even though the trigger value itself isn't platform-restricted server-side | `positive_review_min_rating` (int 1–5, default 5 — "rating at or above this fires"), `review_keyword` (optional string) |
| `low_stock` | A product's stock dropped to/below a threshold | `low_stock_threshold` (int) |
| `stale_inventory` | **Added 2026-07-27, WooCommerce-only for now** — a product's stock hasn't changed in N days (dead-stock alert). Only WooCommerce connections have the polling history this needs; inert (never fires, never errors) on every other platform until their own product polling exists | `stale_days` (int, default 30) |
| `back_in_stock` | **Added 2026-07-28, WooCommerce-only for now** — a product's stock went from 0 back to a positive quantity (restock/backorder-fulfilled alert). Same polling-history dependency as `stale_inventory`, so same platform gap. **This is a product-level signal, not a per-order one** — we can't tell you *which specific pending order* can now ship, only that the SKU is sellable again; don't word the notification/UI as if it's confirming a specific order | none — the condition is binary, nothing to configure |
| `order_spike` | **Premium only** — order volume anomaly | `spike_count` (int), `spike_window_minutes` (int) |
| `refund_spike` | **Premium only** — refund volume anomaly | `spike_count` (int), `spike_window_minutes` (int) |
| `digest` | A custom recurring summary (distinct from the free-tier daily digest). **`monthly` added 2026-07-27 (Plan §4.21)** — a real monthly business report, not just a bigger digest: covers the previous calendar month, and teams on `analytics_level: full` (Pro+) get an extra per-channel breakdown + top-3 products appended to the body | `digest_frequency` (`"daily"`\|`"weekly"`\|`"monthly"`), `digest_time` (`"HH:mm"`), `digest_day_of_week` (0–6, Sunday=0, weekly only), `digest_day_of_month` (1–28, monthly only, default 1) |
| `ai_insight` | **Premium only** — an unprompted AI-detected anomaly (Plan §4.12) | none — this one is entirely system-driven, don't build a controls UI for it, just let the merchant enable/disable + pick actions |

**`review_keyword` (added 2026-07-27, shared by `negative_review`/`positive_review`)**: optional string, max 100 chars, case-insensitive substring match against the review's text. Narrows the trigger further — e.g. "only low ratings that also mention 'broken'" — rather than replacing the rating threshold. Omit/leave blank for "any matching-rating review."

**Plan gating you must check client-side before offering these** (read from `GET /me`'s `entitlements.limits`, same keys used elsewhere):
- `order_spike`/`refund_spike` — require `advanced_triggers_enabled: true`
- `ai_insight` — requires `ai_proactive_insights_enabled: true`
- Every other trigger, including `positive_review`/`stale_inventory`/`back_in_stock`, is available from Starter up (Free gets presets only — see below) — they're **not** Premium-gated, same treatment as `low_stock`/`negative_review`
- `priority` and monthly digests need no separate entitlement check — both are plain fields on a custom rule, so `max_rules` (below) is the only gate that applies
- `max_rules` (int\|null) — the create button should be disabled/paywalled once the team is at this count; `null` = unlimited
- **Monthly digest's richer content** (per-channel breakdown + top products) depends on `analytics_level: full` (Pro+) — a Starter team's monthly rule still saves and fires fine, its digest body is just the plain summary line, same as daily/weekly

**Free plan note:** `max_rules` is `0` on Free — the create-rule screen shouldn't be reachable at all on Free, route straight to the upgrade paywall instead of showing an empty form that will 422.

---

## Condition vocabulary

`conditions` shape: `{ "all": [...], "any": [...] }` — a rule matches when **every** `all` condition is true, AND (**at least one** `any` condition is true, OR `any` is empty/omitted). Both keys are optional; omit `conditions` entirely for a trigger that doesn't need any (e.g. plain `new_order`).

Each condition item: `{ "field": "...", "operator": "...", "value": ... }`.

**`field`** — exactly these 12 values, and what UI control each one implies:
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
| `shipping_state` | Order's `shipping_address.state` — **added 2026-07-27** | Same honesty as `customer_country` above — raw platform-sent text (e.g. a US state code, or a full province name depending on platform), no fixed enum and no "list of states seen" endpoint. Free text or a bundled state/province picker client-side, not derived from this API. |
| `customer_order_count` | Total orders this team has from the order's customer email, **including the order that's about to trigger the rule** — **added 2026-07-27** | Numeric input. This is what makes "notify me on someone's Nth order" work: `operator: "eq"`, `value: 5` fires exactly on a customer's 5th order. `0` for a guest checkout with no email captured. |

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
  { "id": 1, "name": "High-value order alert", "trigger": "high_value_order", "conditions": {"all": [{"field": "total", "operator": "gte", "value": 200}]}, "actions": [{"type": "push"}], "sound": null, "priority": "high", "controls": {"quiet_hours": {"start": "22:00", "end": "08:00", "timezone": "Australia/Sydney"}}, "enabled": true, "created_at": "2026-07-10T00:00:00.000000Z" }
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
| `priority` | **added 2026-07-27 (Plan §4.20)** — optional, one of `normal` (default) `high` `critical`. Not just a label: `high`/`critical` push notifications are sent at FCM/APNs's "deliver now" priority tier, so this is worth exposing as a real setting, not decoration |
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
| 429 | A duplicate submission — the *identical* `name`/`trigger`/`conditions`/`actions`/`controls` payload was just submitted seconds ago | `"This rule was just submitted — please wait a moment before trying again."` |

**Revised 2026-07-30 — a double-tap creating two identical rules is now guarded server-side.** This one mattered more than a typical duplicate: an accidentally-duplicated rule doesn't just create clutter once, it keeps firing on every future match *forever*, doubling every push/email/SMS for that trigger until someone notices and deletes the extra rule. The guard is keyed to the exact payload, so submitting a genuinely different rule (even seconds later) is never blocked — only resubmitting the identical one. Still disable the "Create rule" button on tap as normal practice; this is the server-side backstop, see `network-resilience-and-edge-cases.md`.

## `PUT /rules/{id}`

**Requires auth**, `owner`/`manager` role. Same field rules as `POST`, all optional (partial update) except that the same plan-gate checks re-run if you're **changing** `trigger` to a gated one — this closes a real gap: a Starter team can't bypass the Premium gate by creating an allowed-trigger rule and then editing it to `order_spike`.

**Success — 200:** `{rule: {...}}`. **Errors:** 404 if not your team's rule, same 422s as create for anything that fails validation/gating.

## `POST /rules/{id}/test`

**Requires auth**, `owner`/`manager` role. Fires the rule for real right now — **not a dry run**, it really sends the push/email/SMS and really logs an execution. Use this for a "Test this rule" button so the merchant can confirm it works before waiting for it to trigger naturally.

Repeatable — calling it again always works (bypasses conditions/cooldown/quiet-hours entirely, not just the per-order dedup).

```json
{ "success": true, "message": null, "data": { "execution": {
  "id": 10, "order_id": null, "trigger": "test_fire",
  "actions_result": [{"type": "push", "status": "sent"}], "fired_at": "2026-07-16T02:00:00.000000Z"
} } }
```
**`trigger` is always the literal string `"test_fire"` here, never the rule's own trigger type** — a real, easy assumption to get wrong if you're inferring the icon/label for an execution row from its `trigger` field. Handle `"test_fire"` as its own case in any executions-list rendering, distinct from `"high_value_order"` etc.

**`actions_result[].status` varies by action type — full real vocabulary:**
| Action type | Possible `status` values |
|---|---|
| `push` | `sent`, `failed` (FCM rejected every device), `no_devices` (no registered push tokens), `muted_by_preference` (recipient disabled push in Settings), `quiet_hours` (recipient's own personal quiet hours, Settings-level — **not** the rule's `controls.quiet_hours`, see below), `muted_by_store`, `bundled_suppressed` (storm-protection bundling swallowed an individual ping — order-triggered pushes only) |
| `email` | `sent`, `quota_exceeded` (team's monthly email cap), `muted_by_preference`, `quiet_hours` (same personal-preference caveat as push), `muted_by_store` |
| `sms` | `sent`, `failed` (Twilio API call failed), `insufficient_credit`, `no_phone_number`, `not_yet_available` (Twilio not configured for this environment), `muted_by_store` |
| `notify_member` | Same as `push` above (it delegates to the same send), plus `not_a_team_member` (the `user_id` isn't on this team) or `missing_user_id` (the action itself has no `user_id` at all — a malformed action, shouldn't happen since `POST /rules` validates this) |
| `auto_tag` | `tagged`, `already_tagged` (idempotent, not an error), `skipped_no_order` (order-less trigger — auto-tag has nothing to tag) |

Show these plainly in a test-fire result screen rather than just "success"/"failure" — they're genuinely informative (e.g. `no_devices` tells the merchant to check their push permissions, not that something's broken server-side).

**Two unrelated "quiet hours" concepts — don't conflate them:**
1. **Rule-level** (`controls.quiet_hours` on the rule itself) — checked *before* any actions dispatch. If within the window, the **entire execution** is skipped and logged as `actions_result: [{"status": "skipped_quiet_hours"}]` — note this single entry has **no `type` key at all**, unlike every other entry in this array, since no individual action ever ran.
2. **Per-recipient** (`quiet_hours` as an individual action's `status`, above) — the specific recipient has their *own* personal quiet hours set in `settings-api-reference.md`'s notification preferences. The rule still fired and dispatched normally; this one recipient's push/email just didn't go out to them personally.

**`muted_by_store` (added 2026-07-23) never appears in a test-fire result** — test-fire has no order/subject behind it, so there's no store connection to check, regardless of any store's mute setting (`connections-api-reference.md`'s `PATCH /connections/{id}`). It's a real value only in `GET /rules/{id}/executions` below, for genuine firings.

## `GET /rules/{id}/executions`

**Requires auth.** Most recent 50 firings, newest first, same shape as the `test` response's `execution` object. `order_id` is `null` for order-less triggers (`digest`, `low_stock`, `stale_inventory`, `back_in_stock`, `negative_review`, `positive_review`, `ai_insight`).

**`actions_result[].status` can also be `muted_by_store` here** (added 2026-07-23) — the firing's store connection has `notifications_muted: true` (`connections-api-reference.md`). Applies to every trigger that resolves to a real store — every order-scoped trigger, plus `low_stock`/`stale_inventory`/`back_in_stock`/`negative_review`/`positive_review` — but never `digest`/`ai_insight`, which summarize across every connected store at once and so have no single store to mute against.

---

## Platform coverage — which triggers actually have data behind them

The app doesn't reject a rule just because its trigger has nothing to fire from on a seller's connected platforms — it saves fine and simply never fires. Worth surfacing in the UI so a seller doesn't wonder why a rule "isn't working":

| Trigger | Real on |
|---|---|
| Every order-linked trigger (`new_order`, `high_value_order`, `unfulfilled_after_x`, `ship_by_deadline`, `refund_requested`, `order_cancelled`, `payment_failed`, `order_spike`, `refund_spike`) and every condition that reads off an order (including `shipping_state`, `customer_order_count`) | All 6 connected-platform types — real order sync exists everywhere now |
| `low_stock` | Shopify, WooCommerce, eBay (real inventory polling via `PollEbayInventoryJob`/`products:poll-ebay`, every 30 min — this row was stale/wrong until 2026-07-28, eBay support existed in code well before this doc caught up) |
| `stale_inventory` | **WooCommerce only** — Shopify's inventory sync is webhook-driven and doesn't write the stock-history table this trigger reads |
| `back_in_stock` | **WooCommerce only** — same stock-history table as `stale_inventory`, same gap |
| `negative_review` | WooCommerce, eBay |
| `positive_review` | **WooCommerce only** — eBay's feedback poller only ever fetches negative feedback from eBay's API, so it structurally never sees a positive review to check |

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
