# StockBeat Mobile — Payouts Screen

Pair with `payouts-api-reference.md` — **read its opening note first**: this isn't a Plan §4 bottom-nav screen, it's a read-only reconciliation view. Entry point below is a recommendation, not a spec requirement.

---

## Entry point: Settings/More

Row 6 in `MoreScreen` (`settings-flow-screens.md`'s Screen 1), between "Customers" and "Reviews" — an occasional glance, not a primary tab, same reasoning as Products.

---

## Screen — `PayoutsScreen`

**On load:** `GET /payouts`.

**List:** one row per payout — amount (formatted in its own `currency`, not converted to the team's base currency), status badge, arrival date. Order is already newest-first from the API, don't re-sort client-side.

**Status badge styling:** `paid` = success/green, `in_transit` = info/blue ("on its way"), `scheduled` = neutral/grey ("upcoming"), `failed`/`canceled` = error/red. Don't collapse `scheduled` and `in_transit` into one "pending" look — they're meaningfully different states (not yet processed vs. already sent).

**No tap action, no swipe action, nothing editable.** This is purely informational — resist the urge to add a detail screen or action sheet unless a real need shows up later; the API itself has no single-payout `GET` endpoint to back one.

**Platform-gating note:** since this only ever has real data for Shopify, consider a one-line explainer at the top of the screen the first time a seller with only non-Shopify stores opens it ("Payouts are currently available for Shopify Payments only") rather than just showing a bare empty state that reads as broken.

**Empty state:** "No payouts yet" — either genuinely no payouts have landed yet for a connected Shopify store, or the seller has no Shopify store connected at all; the API doesn't distinguish these two cases (an empty array either way), so don't try to give a more specific empty-state message than the data actually supports.
