# StockBeat Mobile — Customers Screens

Pair with `customers-api-reference.md` — **read its opening note first**: no `customers` table exists, this is purely an aggregation over orders, so there's no customer detail endpoint — the "detail" screen below is just the existing order feed, filtered.

---

## Entry point: Settings/More

Row 5 in `MoreScreen` (`settings-flow-screens.md`'s Screen 1) — an occasional lookup, not a primary tab, same reasoning as Products/Payouts/Reviews.

---

## Screen 1 — `CustomersScreen`

**On load:** `GET /customers`.

**List:** customer name (or the email itself if `customer_name` is null — don't show a blank), email as a smaller subdued line beneath, order count and total spent right-aligned. Show total spent as "—" (not "$0.00") when the API returns `null` for it — see the API doc's honesty caveat on why that happens.

**Tap a row** → Screen 2, passing that row's `customer_email`.

**Empty state:** "No customers yet" — this list is entirely derived from synced orders, never manually created.

## Screen 2 — `CustomerDetailScreen`

**On load:** `GET /orders?customer_email=<the tapped row's email>` — literally the same order feed the main Feed tab uses (`orders-api-reference.md`), just pre-filtered. Reuse the exact same order-row component, tap-through-to-detail, and pagination/cursor logic already built for the main Feed — **do not build a second order-list component for this screen.**

**Header:** the customer's name/email and the same summary stats already shown in the list row (order count, total spent) — no second network call needed for these, pass them through from Screen 1's tapped row.
