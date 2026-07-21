# StockBeat Mobile — Feed & Order Detail Screens

Depends on at least one store being connected (`connections-flow-screens.md`, Screen 4 `ConnectionSuccessScreen` lands here). Pair with `orders-api-reference.md` for exact request/response shapes.

Per Plan §4.10's navigation spec, this is the **first of four bottom tabs**: Feed · Rules · Inbox · More. This doc covers Feed and order detail only.

---

## Screen 1 — `FeedScreen` (Tab 1, default landing screen)

**Purpose:** the unified order feed across every connected store, with today's numbers up top.

**Layout, top to bottom:**

1. **Analytics header** — call `GET /analytics/summary?range=today` on load. Show revenue + order count prominently ("Today: $240.00 · 3 orders"). If `entitlements.limits.analytics_level` (from `GET /me`) is `"7d"` or `"full"`, offer a range switcher (Today / 7d / 30d, capped to what the plan allows); tapping a disallowed range should open the upgrade paywall directly rather than firing the request and handling the 422 — you already know client-side which ranges are allowed.
2. **Filter bar** — channel (platform icons, multi-select or single), status, date range, value range, tag. Keep this collapsed/summary by default (Plan §4.10: "zero-training-needed") — a filter icon that expands, not a permanently-visible form.
3. **Search** — a search field wired to `q`, debounced (don't fire a request per keystroke).
4. **Order list** — cursor-paginated (`orders-api-reference.md`'s `cursor` param). Infinite scroll: on reaching the end, if `next_cursor !== null`, fetch the next page and append; stop when it's `null`.

**Each row shows:**
- Order number, customer name, total (in the order's own `currency`, not `total_base_currency` — show the merchant's actual currency per order, don't silently convert).
- Status badge — color-code `status` (new/unfulfilled/shipped/refunded/cancelled), not `fulfillment_status`/`payment_status` (those are secondary, show as smaller text/icons if there's room).
- **Ship-by urgency**: if `is_ship_by_urgent` is true, a visible red/amber accent — this is the "don't miss a deadline" signal Plan calls out as core to the value prop. If `ship_by_hours_remaining` is negative, show it as overdue, not just omit it.
- Tags as small chips if present.
- Platform icon (from `platform`).

**Pull-to-refresh:** re-fetches page 1 (no cursor), replaces the list — standard pattern, nothing platform-specific here.

**Empty states** (Plan §4.10: "empty states teach"):
- No orders at all yet (first connection, nothing synced): "Your orders will show up here as they come in" — not an error state, this is expected right after connecting.
- No orders matching the current filters: "No orders match these filters" + a clear "Clear filters" action.

**Snoozed orders:** hidden by default. If you build a "show snoozed" toggle in the filter bar, it maps to `include_snoozed=true`.

**On tap of a row:** navigate to `OrderDetailScreen` (Screen 2) with the order's `id`.

---

## Screen 2 — `OrderDetailScreen`

**Params:** `order_id`.

**On load:** `GET /orders/{id}` (includes `items` and `notes`, unlike the list endpoint).

**Layout:**
1. Order header — number, status badges, placed-at date, ship-by countdown if present.
2. Customer info — name, email, shipping address.
3. **Discount/tax line, only if present** (`discount_amount`/`tax` — will be `null` for every platform except WooCommerce today, per the API reference; don't render a "$0.00 discount" row when it's `null`, omit the row entirely).
4. Line items — SKU, title, image, qty, price.
5. Notes — list existing (`notes`), plus an "Add note" input → `POST /orders/{id}/notes`, append to the list on success (201), no need to re-fetch the whole order.
6. Tags — editable chip list. On add/remove, `POST /orders/{id}/tags` with the **full resulting array** (see API reference — this replaces, not appends).
7. **Quick action buttons** — see below.
8. "Message customer" button → opens a simple compose sheet, `POST /orders/{id}/message` with `{body}`. (Full inbox thread UI is a separate future module — this can be a single-shot "send a message" action for now, per the API reference's note.)
9. "Share packing slip" → `GET /orders/{id}/packing-slip`, hand the PDF response to the native share sheet.

### Quick action buttons — capability-gated, don't just always show all four

Before rendering fulfill/refund/cancel buttons, check this order's connection's `capabilities` (from `GET /connections`, `connections-api-reference.md`) — you'll need to have that connection list cached/available on this screen, keyed by `connection_id`:
- `capabilities.fulfill_tracking` → show "Mark fulfilled"
- `capabilities.refunds` → show "Refund"
- `capabilities.cancel` → show "Cancel order"

**Even with client-side gating, still handle the 422 gracefully** — the server enforces the same check independently (Plan §8.3: "server-enforced... rather than trusting the mobile app only shows the button when supported"), and it's the authority if the two ever disagree (e.g. a capability changes server-side without an app release).

**"Mark fulfilled" sheet:** tracking number (required text input) + carrier (optional text input, not a picker — carriers aren't standardized across platforms). Submit → `POST /orders/{id}/fulfill`. **This is a real live call to the platform**, not instant — show a loading state on the submit button, disable double-submit.

**"Refund" sheet:** amount (optional numeric input, pre-fill placeholder as the order total but leave the field genuinely empty by default so omitting it correctly triggers a full refund — don't auto-fill a value that then gets sent as a partial refund by accident) + reason (optional text). Client-side validate amount ≤ order total before submit (server also checks, but catch the obvious case early). Submit → `POST /orders/{id}/refund`.

**"Cancel order" sheet:** reason (optional text) + a confirm step — cancelling is not reversible from this app. Submit → `POST /orders/{id}/cancel`.

All three: on success, update the order in local state from the response's `order` object (status/fulfillment_status/payment_status will have changed) rather than re-fetching — the response already has everything.

**On a 422 "not supported" response** (shouldn't normally happen if you gated correctly, but handle it): show the server's message (`errors.order[0]`) as a plain alert/toast, don't treat it like a form validation error under a specific field.

---

## Snooze action

Not a dedicated screen — an action reachable from the order row (swipe action) or order detail (menu item): a date picker → `POST /orders/{id}/snooze {until: "<iso8601>"}`. To un-snooze, same endpoint with `until: null` (must send the key explicitly, see API reference — omitting it is a validation error, not a no-op).

---

## Edge case: an order from a platform whose connection was later disconnected/paused

Orders stay in the feed even after their connection is `disconnected`/`paused` (historical record — `connections-api-reference.md`'s `DELETE /connections/{id}` note). On such an order's detail screen, quick actions will 422 with the "not supported" message (or possibly a different failure depending on what the adapter does against a dead connection) — since you can't know client-side that the connection went away without also fetching `GET /connections`, don't assume this order's capabilities are still accurate from a stale cache; either re-check on order-detail load, or just let the 422 handle it gracefully as designed above.

## Edge case: multi-currency orders

`total` is always in that order's own `currency` — a merchant with both a USD Shopify store and an AUD WooCommerce store will see mixed currencies in one feed. Always render the currency symbol/code next to each amount, never assume a single global currency for the whole list. `total_base_currency` (when non-null) is what you'd sum for a cross-store total — the Feed header's `GET /analytics/summary` already does this server-side (`revenue_base`), don't try to sum `total` values across mixed-currency orders client-side yourself.
