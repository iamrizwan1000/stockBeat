# StockBeat Mobile — Customers API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

**Not a Plan §4 bottom-nav screen** — same "Phase infrastructure needing a UI home" situation as `products-api-reference.md`/`payouts-api-reference.md`. This is a query-time aggregation over `orders` — **there is no `customers` table**, so there's no `GET /customers/{id}`, no create/update/delete, and no way to edit a customer's name or anything else from here. For a customer's full order history, use `GET /orders?customer_email=...` (`orders-api-reference.md`), not a separate endpoint on this resource.

---

## `GET /customers`

**Requires auth.** No pagination (bounded by order volume within the plan's history window, same reasoning as `payouts-api-reference.md`). Grouped by `customer_email`, ordered by most recent order first.

```json
{ "success": true, "message": null, "data": { "customers": [
  { "customer_email": "alex@example.com", "customer_name": "Alex Chen", "order_count": 2, "total_spent": 80.0, "last_order_at": "2026-07-26T00:00:00.000000Z" }
] } }
```

| Field | Type | Notes |
|---|---|---|
| `customer_name` | string\|null | The most recent non-null name seen for this email — a platform occasionally omits a name on one order but not another, this always shows the latest known one, not necessarily the first order's name |
| `order_count` | int | **Always the true, complete count** for this email within the plan's history window — never affected by the `total_spent` caveat below |
| `total_spent` | float\|null | Sum of `total_base_currency` across this customer's orders. **`null` (not `0`) when none of their orders have a resolved base-currency total** (§9's `fx_rates` gap) — render "—" or "Not available," never "$0.00," which would fabricate a real number that isn't true. If some but not all orders are missing conversion, the sum silently reflects only the ones that do — a known, accepted undercount rather than blocking the whole feature on a rare multi-currency edge case. |
| `last_order_at` | string | ISO 8601 |

**No row for a customer whose only orders have a `null` `customer_email`** — this happens after a GDPR erasure request (`connections-api-reference.md`'s GDPR notes) zeroes out that field; those orders still exist and still show in the plain order feed, they just can't be grouped into an identity anymore, so they're excluded here entirely rather than shown as an anonymous "Unknown customer" row.

**Same `history_days`/store-visibility gating as everywhere else** — a restricted team member (`settings-flow-screens.md`'s `TeamScreen`) only sees customers from stores they have visibility into, and a Free-tier team's customer list only reflects orders within their plan's history window, same as the main Feed.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success (an empty `customers` array is a normal, valid response) |
| 401 | Missing/invalid/revoked bearer token |
