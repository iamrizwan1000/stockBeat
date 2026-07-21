# StockBeat Mobile — Products (Cost Price) API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

**Not a bottom-nav screen in the original Plan §4 spec** — this was added as Phase B backend infrastructure (Plan §4.12/§15, built 2026-07-22) purely to power the AI Data Copilot's profit and restock tools (`ai-api-reference.md`). It needs *some* UI so a seller can actually enter cost prices, or the AI's profit/restock answers will always report every item as excluded — but where exactly that UI lives is a placement decision, not something dictated by the backend. `settings-flow-screens.md`'s More menu is the natural fit (an occasional setup task, not a primary destination) — see this doc's flow-screens pair for the recommended placement.

**Don't confuse this with `GET /analytics/products`** (`orders-api-reference.md`) — that's a **read-only "top sellers by revenue/units" report** for a date range, a completely different endpoint with a completely different shape. This doc's `GET /products` is the **full polled product catalog with stock levels**, the one place `cost_price` can be read or edited.

---

## `GET /products`

**Requires auth.** No pagination, no filters — returns the team's entire polled product catalog, alphabetical by title.

```json
{ "success": true, "message": null, "data": { "products": [
  { "id": 1, "connection_id": 1, "sku": "VNT-014", "title": "Vintage Denim Jacket", "stock_quantity": 6, "cost_price": 22.50 }
] } }
```

| Field | Type | Notes |
|---|---|---|
| `connection_id` | int | Which store connection this product came from — cross-reference `GET /connections` (`connections-api-reference.md`) if you want to show the store name per product |
| `sku` | string\|null | Not every platform/product guarantees a SKU |
| `stock_quantity` | int\|null | Last polled stock level — **not real-time**, this is whatever the last sync cycle saw, same staleness caveat as anywhere else stock is shown in this app |
| `cost_price` | float\|null | **The only editable field.** `null` means "not entered yet," not zero — see below |

**These are polled/synced products, not a catalog you create from this app** — there's no `POST /products` to add a new one. If a product a seller expects isn't in this list, that's a sync/connection issue (check `GET /connections/{id}/health`, `connections-api-reference.md`), not something fixable by creating it manually here.

## `PUT /products/{id}/cost-price`

**Requires auth**, `owner`/`manager` role.

```json
{ "cost_price": 22.50 }
```

| Field | Rules |
|---|---|
| `cost_price` | nullable, numeric, min 0, max 999999.99 |

**Send `null` explicitly to clear a previously-set cost price** — this is a real, meaningful action, not a no-op: clearing it removes the product from profit calculations entirely rather than treating it as zero-cost (which would fabricate a 100% margin — the backend deliberately never does this). If you're building a "clear" control in the UI, make sure it actually sends `{cost_price: null}` rather than omitting the field or sending `0`.

**Success — 200:**
```json
{ "success": true, "message": null, "data": { "product": {
  "id": 1, "connection_id": 1, "sku": "VNT-014", "title": "Vintage Denim Jacket", "stock_quantity": 6, "cost_price": 22.50
} } }
```

**404** if the product doesn't belong to your team.

## `PUT /products/cost-prices` — bulk update

**Requires auth**, `owner`/`manager` role. Added 2026-07-22 to close the original one-at-a-time gap for sellers with a large catalog.

```json
{ "updates": [
  { "id": 1, "cost_price": 22.50 },
  { "id": 2, "cost_price": null }
] }
```

| Field | Rules |
|---|---|
| `updates` | required, array, 1–500 items |
| `updates.*.id` | required, integer |
| `updates.*.cost_price` | nullable, numeric, min 0, max 999999.99 — same "null clears, doesn't zero" semantics as the single-item endpoint |

**Atomic — all or nothing.** If *any* `id` in the batch doesn't belong to your team, the **entire call fails** with a 422 and **nothing is written**, not even the valid items. This is a deliberate design choice: it means you never have to reconcile "which of my 40 edits actually saved" — either the whole batch you sent applied, or none of it did, so a failure means resend the same batch after fixing whatever's wrong (almost certainly a stale/deleted product id from a local list that's gone out of sync — refetch `GET /products` and retry).

**Success — 200:** `{products: [...]}` — the full updated set for every id in the batch, same shape as `GET /products`' items, in no particular guaranteed order (match by `id`, don't assume it echoes your input order).

**A duplicate `id` within one batch is allowed, not an error** — the last occurrence for that id wins; avoid sending duplicates on purpose, but a client bug that accidentally does won't 422.

---

## Why this matters — connect it to the AI Assistant

Cost prices entered here are what makes `ai-api-reference.md`'s `get_profit_summary`/`get_restock_recommendations` tools give complete answers instead of partial ones. Consider surfacing this connection in the UI — e.g., if the AI Assistant's profit answer mentions items were excluded for missing cost price, a "Set cost prices" deep link straight into this screen closes the loop for the merchant, rather than leaving them to discover this screen exists on their own.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Product doesn't belong to your team (single-item endpoint only — the bulk endpoint reports the same situation as a 422, see above) |
| 422 | `cost_price` fails validation (negative, non-numeric, over the max), or — bulk only — one or more `id`s in the batch don't belong to your team, or `updates` is empty/missing/over 500 items |
