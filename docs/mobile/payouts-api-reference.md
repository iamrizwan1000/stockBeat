# StockBeat Mobile — Payouts API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

**Not a Plan §4 bottom-nav screen** — same "Phase infrastructure needing a UI home" situation as `products-api-reference.md`'s cost-price feature. This is a read-only reconciliation view: what actually landed in the seller's bank account, distinct from order **revenue** (`analytics-api-reference.md`'s `GET /analytics/summary`). No write endpoints exist at all for this resource — don't build an edit/delete affordance, there's nothing to build it against.

**Shopify only, v1.** Every other platform (WooCommerce, eBay, Etsy, Amazon, TikTok) simply has zero rows here — that's expected, not an error. WooCommerce has no native payout concept at all (it depends on whatever payment gateway plugin the merchant uses), and eBay/Etsy/Amazon were deferred as a deliberate scope decision (Plan §4.14), not an oversight.

---

## `GET /payouts`

**Requires auth.** No pagination (payout volume is naturally low — typically one entry per day/week per store). Optional `connection_id` query param to filter to one store; omit it to see every connected store's payouts together, newest first.

```json
{ "success": true, "message": null, "data": { "payouts": [
  { "id": 1, "connection_id": 1, "amount": 482.19, "currency": "USD", "status": "paid", "arrival_date": "2026-07-24" }
] } }
```

| Field | Type | Notes |
|---|---|---|
| `connection_id` | int | Cross-reference `GET /connections` for the store name/platform, same pattern as `products-api-reference.md` |
| `amount` | float | The actual payout amount, already net of platform fees — not order revenue |
| `currency` | string | ISO currency code, whatever Shopify reports for that payout |
| `status` | string | **Shopify's own vocabulary, passed through as-is**: `scheduled`, `in_transit`, `paid`, `failed`, or `canceled` — not mapped to an invented enum. Render each distinctly; don't collapse `scheduled`/`in_transit` into a single "pending" bucket, they mean different things (not yet processed vs. already sent, awaiting bank clearance). |
| `arrival_date` | string\|null | `YYYY-MM-DD`. Null is possible for a `scheduled` payout that doesn't have a confirmed date yet — show "Date pending" rather than a blank or `"null"` string. |

**No entries for a connection whose platform isn't Shopify** — before showing a "Payouts" section for a given store, check that connection's `platform` (`GET /connections`) is `shopify`; don't render an empty "no payouts yet" state for a WooCommerce/eBay/Etsy/Amazon/TikTok store implying the feature is just waiting to sync, since it never will for those platforms in v1.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success (an empty `payouts` array is a normal, valid response — not an error) |
| 401 | Missing/invalid/revoked bearer token |
