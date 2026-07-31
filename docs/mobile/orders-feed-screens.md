# StockBeat Mobile — Feed & Order Detail Screens

Depends on at least one store being connected (`connections-flow-screens.md`, Screen 4 `ConnectionSuccessScreen` lands here). Pair with `orders-api-reference.md` for exact request/response shapes.

Per Plan §4.10's navigation spec, this is the **first of four bottom tabs**: Feed · Rules · Inbox · More. This doc covers Feed and order detail only.

---

## Screen 1 — `FeedScreen` (Tab 1, default landing screen)

**Purpose:** the unified order feed across every connected store, with today's numbers up top.

**Layout, top to bottom:**

1. **Analytics header** — call `GET /analytics/summary?range=today` on load. Show revenue + order count prominently ("Today: $240.00 · 3 orders"). If `entitlements.limits.analytics_level` (from `GET /me`) is `"7d"` or `"full"`, offer a range switcher (Today / 7d / 30d, capped to what the plan allows); tapping a disallowed range should open the upgrade paywall directly rather than firing the request and handling the 422 — you already know client-side which ranges are allowed.
2. **Filter bar** — channel (platform icons, multi-select or single), status, date range, value range, tag. **The channel/platform filter's options must be derived from this team's actual `GET /connections` list (`connections-api-reference.md`), never a static list of all possible platforms (added 2026-07-31).** A team can only ever have data for platforms they've actually connected — showing a filter chip for a platform they have zero connections/orders for is a guaranteed-empty dead end, and worse right now specifically: only Shopify is launched (`connections-api-reference.md`'s platform status section), so every other platform chip would be permanently empty for every team, not just occasionally. This also means the filter bar doesn't need any "coming soon" treatment at all — it naturally only ever shows what's real for this team, and stays correct automatically as more platforms launch later without needing another doc/app update to match. Keep this collapsed/summary by default (Plan §4.10: "zero-training-needed") — a filter icon that expands, not a permanently-visible form. **Favorite/saved filters (added 2026-07-27, Plan §4.23, free on every plan):** a "Saved" chip/dropdown in this same bar, populated from `GET /order-filters` — tapping one applies its `filters` object straight into this screen's filter state (no special "apply" endpoint). A "Save current filters" action (only shown when at least one filter is active) opens a name prompt → `POST /order-filters`.
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

**Bulk select mode — added 2026-07-26 (Plan §4.17):** long-press a row to enter multi-select (checkboxes appear on every row); tapping rows toggles selection. Only offer this mode at all if `entitlements.limits.bulk_actions_enabled` (from `GET /me`) is `true` — on a Free team, either hide the long-press affordance entirely or let it open the upgrade paywall directly, the same "don't fire the request and handle the 403" pattern used for a disallowed analytics range above. While selected, show a bottom action bar with two buttons:
- **"Tag"** → a single text input for one tag → `POST /orders/bulk-tag {ids, tag}`. This *adds* the tag to each selected order (existing tags kept) — don't build this as "replace this order's tags," that's the single-order flow below.
- **"Cancel"** → confirm step (not reversible) → `POST /orders/bulk-cancel {ids, reason}`. **Always render the per-id `results` array**, even on a 200 — e.g. "18 of 20 cancelled" with an expandable list of which ones failed and why, never a single pass/fail toast, since a channel-capability failure on one order doesn't mean the whole batch failed.
- **"Share packing slips"** (added 2026-07-27, Plan §4.22) → `POST /orders/bulk-packing-slips {ids}` (max 100 selected), hand the returned multi-page PDF to the native share sheet — same mechanism as the single-order packing slip, just one order per page.

---

## Screen 2 — `OrderDetailScreen`

**Params:** `order_id`.

**On load:** `GET /orders/{id}` (includes `items`, `notes`, and (added 2026-07-27) `events`, unlike the list endpoint).

**Layout:**
1. Order header — number, status badges, placed-at date, ship-by countdown if present.
2. Customer info — name, email, shipping address.
3. **Discount/tax line, only if present** (`discount_amount`/`tax` — will be `null` for every platform except WooCommerce today, per the API reference; don't render a "$0.00 discount" row when it's `null`, omit the row entirely).
4. Line items — SKU, title, image, qty, price.
5. Notes — list existing (`notes`), plus an "Add note" input → `POST /orders/{id}/notes`, append to the list on success (201), no need to re-fetch the whole order.
6. Tags — editable chip list. On add/remove, `POST /orders/{id}/tags` with the **full resulting array** (see API reference — this replaces, not appends).
7. **Quick action buttons** — see below.
8. "Message customer" button → opens a simple compose sheet, `POST /orders/{id}/message` with `{body}`. (Full inbox thread UI is a separate future module — this can be a single-shot "send a message" action for now, per the API reference's note.)
9. "Share packing slip" → `GET /orders/{id}/packing-slip`, hand the PDF response to the native share sheet. This deliberately has no price on it.
10. "Share invoice" → added 2026-07-26 (Plan §4.18), `GET /orders/{id}/invoice`, same PDF/share-sheet mechanism as the packing slip above but this one has the priced breakdown (line items, subtotal, discount/tax if known, total) — the one to send a customer, not the packing slip. Free on every plan, no capability check needed before showing the button.
11. **Order timeline — added 2026-07-27 (Plan §4.19), free on every plan.** A collapsible "Activity" section rendering `events` top-to-bottom (already oldest-first from the API — don't reverse it). Map each `type` to a fixed icon + one-line label built from `payload` (e.g. `fulfilled` → "Marked fulfilled · UPS 1Z999AA1...", `tags_updated` → "Tags updated: gift, urgent"); `created`/`updated` have no `payload` today, just show a generic "Order received"/"Order synced" line. This is a good source for a real order-detail "what happened" section that didn't exist before — don't build a placeholder timeline out of `status` alone when this real data exists.

### Quick action buttons — capability-gated AND order-state-gated, don't just always show all four

Before rendering fulfill/refund/cancel buttons, check this order's connection's `capabilities` (from `GET /connections`, `connections-api-reference.md`) — you'll need to have that connection list cached/available on this screen, keyed by `connection_id`:
- `capabilities.fulfill_tracking` → show "Mark fulfilled"
- `capabilities.refunds` → show "Refund"
- `capabilities.cancel` → show "Cancel order"

**This alone isn't enough — also check the order's own `status` before showing a button, not just the connection's capability.** All three actions are idempotent server-side (double-tapping just gets back a friendly "already fulfilled/refunded/cancelled" success, never an error or a duplicate action against the platform), but nothing hides the button once an order reaches that state — a real report from testing was seeing "Mark fulfilled" still showing on an order that was already fulfilled directly in Shopify. Hide (or disable) each button once its own action no longer applies:
- "Mark fulfilled" → hide once `status === "shipped"`
- "Refund" → hide once `status === "refunded"`
- "Cancel order" → hide once `status === "cancelled"`

This is a per-order check, re-evaluated every time the order updates (webhook-driven status changes, e.g. a merchant fulfilling directly in Shopify Admin, should make the button disappear here too — don't cache the button's visibility separately from the order's `status` field).

**Even with client-side gating, still handle the 422 gracefully** — the server enforces the connection-capability check independently (Plan §8.3: "server-enforced... rather than trusting the mobile app only shows the button when supported"), and it's the authority if the two ever disagree (e.g. a capability changes server-side without an app release). The order-state check has no equivalent 422 — calling e.g. `POST /orders/{id}/fulfill` on an already-shipped order just returns a normal `200` success with the "already fulfilled" message, so there's no error case to handle there, just a wasted call worth avoiding by hiding the button.

**"Mark fulfilled" sheet:** tracking number (required text input) + carrier (optional text input, not a picker — carriers aren't standardized across platforms). Submit → `POST /orders/{id}/fulfill`. **This is a real live call to the platform**, not instant — show a loading state on the submit button, disable double-submit.

**"Refund" sheet:** amount (optional numeric input, pre-fill placeholder as the order total but leave the field genuinely empty by default so omitting it correctly triggers a full refund — don't auto-fill a value that then gets sent as a partial refund by accident) + reason (optional text). Client-side validate amount ≤ order total before submit (server also checks, but catch the obvious case early). Submit → `POST /orders/{id}/refund`.

**"Cancel order" sheet:** reason (optional text) + a confirm step — cancelling is not reversible from this app. Submit → `POST /orders/{id}/cancel`.

All three: on success, update the order in local state from the response's `order` object (status/fulfillment_status/payment_status will have changed) rather than re-fetching — the response already has everything.

**On any 422 from these three, show the server's message as a plain alert/toast** — don't treat it like a form validation error under a specific field. **Check both possible message locations, don't assume `errors.order[0]` alone covers it**: the capability "not supported" case (shouldn't normally happen if you gated correctly, but handle it) puts its text under `errors.order[0]`, while fulfill's multi-location-split case (`orders-api-reference.md`, added 2026-07-31 — real, if uncommon, on a multi-warehouse Shopify store) puts it in the top-level `message` field instead. Fall back to whichever one is actually populated rather than hardcoding `errors.order[0]`.

---

## Snooze action

Not a dedicated screen — an action reachable from the order row (swipe action) or order detail (menu item): a date picker → `POST /orders/{id}/snooze {until: "<iso8601>"}`. To un-snooze, same endpoint with `until: null` (must send the key explicitly, see API reference — omitting it is a validation error, not a no-op).

---

## Edge case: an order from a platform whose connection was later disconnected/paused

Orders stay in the feed even after their connection is `disconnected`/`paused` (historical record — `connections-api-reference.md`'s `DELETE /connections/{id}` note). On such an order's detail screen, quick actions will 422 with the "not supported" message (or possibly a different failure depending on what the adapter does against a dead connection) — since you can't know client-side that the connection went away without also fetching `GET /connections`, don't assume this order's capabilities are still accurate from a stale cache; either re-check on order-detail load, or just let the 422 handle it gracefully as designed above.

## Edge case: multi-currency orders

`total` is always in that order's own `currency` — a merchant with both a USD Shopify store and an AUD WooCommerce store will see mixed currencies in one feed. Always render the currency symbol/code next to each amount, never assume a single global currency for the whole list. `total_base_currency` (when non-null) is what you'd sum for a cross-store total — the Feed header's `GET /analytics/summary` already does this server-side (`revenue_base`), don't try to sum `total` values across mixed-currency orders client-side yourself.
