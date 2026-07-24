# StockBeat Mobile — Business Overview / Analytics Screen

Not a bottom-nav destination — a drill-down reached from the Feed tab's analytics header. Pair with `analytics-api-reference.md` for exact request/response shapes.

## Where this sits in the app's navigation tree

Pushed onto the **Feed tab's own stack** (Tab 1), not a new tab and not part of the Settings/More stack:

```
Tab 1 "Feed" (bottom nav)
  → FeedScreen                    (orders-feed-screens.md Screen 1 — tap "See full analytics" on the header)
    → BusinessOverviewScreen      (this doc, Screen 1)
```

Reachable **only** at `analytics_level: "full"` (Pro/Premium) — on Free/Starter, the Feed header's analytics section has no "See full analytics" link at all (there's nothing deeper to show — Free/Starter's `GET /analytics/summary` response never includes `comparison`/`goal`, so there's no content this screen could add beyond what the Feed header already shows). Don't build a locked/paywalled version of this screen; it simply isn't offered as an upsell path here — the upsell already lives on the Feed header's range switcher itself (`orders-feed-screens.md`).

---

## Screen 1 — `BusinessOverviewScreen`

**On load:** `GET /analytics/summary?range=7d` (default to 7d on open; the range switcher lets the merchant change it) and `GET /analytics/products?range=7d` in parallel.

**Layout, top to bottom:**

1. **Range switcher** — Today / 7d / 30d segmented control, same three options the Feed header already offers (this screen is always reachable at `full` level, so all three are always available here — no need to re-check gating per range the way the Feed header does for lower tiers).
2. **Top stat row** — three cards: Revenue (`total.revenue_base` if non-null, else `total.revenue` — see the currency note below), Orders (`total.orders_count`), AOV (`total.aov`).
3. **Comparison callout** — `comparison.change_pct`: a green up-arrow card ("Up 22.7% vs. previous 7 days") when positive, red down-arrow when negative. **Omit this card entirely when `comparison.change_pct` is `null`** (the previous period had zero revenue — there's nothing meaningful to compare against, don't render "Up ∞%" or a zero).
4. **Goal tracking** — a progress bar/ring from `goal.pct_of_best_month`, with `goal.current_month_revenue` / `goal.best_month_revenue` as the supporting figures ("$4,200 of $6,100 — your best month"). **Omit this section when `goal.pct_of_best_month` is `null`** (no historical revenue yet to compare against).
5. **Per-channel breakdown** — one row per entry in `by_channel[]`: platform icon + `name`, `revenue`/`orders_count`/`aov` for that store alone, for the selected range.
6. **Top products** — the `GET /analytics/products` list, ranked 1-10 by revenue, each row showing `title`, `sku` (or omit the SKU line entirely when `null` — same "don't show a blank" pattern as `products-flow-screens.md`), `units`, `revenue`.

**Multi-currency note (Premium only — check `entitlements.limits` isn't the right gate here, since Starter/Pro can also have `revenue_base` populated once `fx_rates` exist for their currency pairs; instead, only show this note when `total.revenue_base` is present *and* differs meaningfully from a single-currency figure, i.e. whenever the team has more than one distinct order currency in the range):** a small info-icon label near the top stat row — "Showing consolidated `{base_currency}`" — clarifying the revenue figure blends multiple store currencies via daily FX conversion. When `total.revenue_base` is `null` (no resolved conversion yet for this range), fall back to showing `total.revenue` with **no currency-consolidation claim** — don't imply a blended figure that isn't actually there.

**Range switch behavior:** switching Today/7d/30d re-fetches both endpoints for the new range; treat it as a full screen reload (skeleton loaders), not an incremental update — the top-products list and per-channel breakdown both change shape enough between ranges that partial updates aren't worth the complexity.

**Pull-to-refresh:** re-fetches both endpoints for the currently-selected range.

---

## Empty state

When `total.orders_count` is `0` for every range (a brand-new team with nothing synced yet), render the same screen shell (header, range switcher) with the stat cards, comparison, goal, per-channel, and top-products sections all replaced by a single centered empty state: **"Nothing to show yet"** with supporting copy "Your analytics will appear here once orders start coming in." Don't show zeroed-out cards ("$0.00 revenue," "0 orders") — that reads as broken, not as "you're new here."

---

## Edge case: a range that was allowed a moment ago becomes disallowed mid-session

If a team's plan downgrades while this screen is open (rare, but possible after an `EXPIRATION`/`CANCELLATION` billing event lands), the next range-switch call can 422 with `"Upgrade your plan for more analytics history."` even though the screen was reachable when it opened. Handle this the same way `orders-feed-screens.md`'s Feed header does — treat it as a paywall trigger, not a crash: show the upgrade sheet rather than an error toast, and don't leave the range switcher in a broken selected-but-failed state.
