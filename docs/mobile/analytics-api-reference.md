# StockBeat Mobile — Business Overview & Analytics API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Plan §4.6 ("Business overview & analytics-lite"). Two endpoints, both gated by the same plan-tier logic: the simple "today's numbers" the Feed header shows (`orders-feed-screens.md`), and the deeper comparison/goal-tracking/top-products view that has no home on the Feed itself — see `business-overview-flow-screens.md` for the dedicated screen built for that.

---

## `GET /analytics/summary`

**Requires auth.** `?range=today|7d|30d` (required).

**Success — 200 (Free/Starter — `analytics_level` is `"today"` or `"7d"`):**
```json
{ "success": true, "message": null, "data": {
  "range": "today",
  "total": { "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 },
  "by_channel": [
    { "connection_id": 1, "platform": "woo", "name": "Rivera Vintage Co", "revenue": 240.0, "revenue_base": 240.0, "orders_count": 3, "aov": 80.0 }
  ]
} }
```

**Success — 200 (Pro/Premium — `analytics_level: "full"`, adds `comparison` + `goal`):**
```json
{ "range": "7d",
  "total": { "revenue": 1840.0, "revenue_base": 1840.0, "orders_count": 23, "aov": 80.0 },
  "by_channel": [ "..." ],
  "comparison": { "previous_period_revenue": 1500.0, "change_pct": 22.7 },
  "goal": { "current_month_revenue": 4200.0, "best_month_revenue": 6100.0, "pct_of_best_month": 68.9 }
}
```

### Plan gating

**Only request a `range` your plan allows** — check `entitlements.limits.analytics_level` from `GET /me`: `"today"` allows only `range=today`, `"7d"` allows `today`/`7d`, `"full"` allows all three. Requesting a disallowed range **422s** with `errors.range[0]` = `"Upgrade your plan for more analytics history."` — this is a paywall trigger, open the upgrade sheet directly rather than showing a raw validation error.

`comparison`/`goal` keys are **entirely absent (not `null`)** on Free/Starter — check with an existence check (`isset`/`?.`), not a null check, when deciding whether to render that part of a screen.

### Field-by-field

| Field | Meaning |
|---|---|
| `total.revenue` / `.orders_count` / `.aov` | Team-wide totals for the selected range, in whatever mix of currencies the underlying orders actually used — this is a straight sum, **not currency-converted**. |
| `total.revenue_base` | The same revenue total converted to the team owner's `base_currency` via daily FX rates (`fx_rates` table, `SyncFxRatesAction`) — this is the number to show for a single consolidated figure across multi-currency stores. **Can be `null`** if none of the range's orders have a resolved base-currency conversion yet (e.g. a brand-new currency pair the FX sync hasn't caught up on) — never fabricate a value when this is `null`, show the per-order/per-channel `revenue` breakdown instead. |
| `by_channel[]` | Same `total` shape, one row per connected store (`connection_id`/`platform`/`name` + `revenue`/`revenue_base`/`orders_count`/`aov` for that store alone). |
| `comparison.previous_period_revenue` / `.change_pct` | The prior period of equal length (e.g. the 7 days before the selected 7-day range), and the % change from it to the current period. `change_pct` is `null` if the previous period had zero revenue (can't compute a meaningful percentage change from zero). |
| `goal.current_month_revenue` / `.best_month_revenue` / `.pct_of_best_month` | Progress toward the team's best calendar month ever (by revenue), not a merchant-set target — there's no goal-setting endpoint, this is always computed against historical performance. `pct_of_best_month` is `null` only if `best_month_revenue` is `0` (no historical revenue at all yet). |

**"Today" is always computed live** from real `orders` rows, never from the `daily_stats` pre-aggregation table — a still-open trading day can't be pre-aggregated. Historical days (anything before today) come from `daily_stats`, rolled up by the nightly `analytics:aggregate-daily` scheduled job. Test orders (`is_test: true`) are excluded from every figure on this endpoint.

---

## `GET /analytics/products`

Top products by revenue within the same range, same plan gating as `GET /analytics/summary` above (a disallowed range 422s identically).

```json
{ "success": true, "message": null, "data": { "products": [
  { "sku": "VNT-014", "title": "Vintage Denim Jacket", "units": 12, "revenue": 1008.0 }
] } }
```

Grouped by SKU where present; line items with no SKU are grouped by title instead (`sku: null` in the response for those). Sorted by revenue descending, capped at the top 10. This is a pure sales-performance ranking — it has no relationship to `products-api-reference.md`'s `GET /products` (the cost-price catalog); don't try to cross-reference SKUs between the two for anything beyond display, they're unrelated concerns.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 422 | `range` not allowed on the team's current `analytics_level` — upgrade-paywall trigger, not a form-validation error |
