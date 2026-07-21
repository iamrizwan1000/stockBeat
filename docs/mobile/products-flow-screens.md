# StockBeat Mobile — Product Cost Prices Screens

Pair with `products-api-reference.md` — **read its opening note first**: this isn't a Plan §4 bottom-nav screen, it's Phase B infrastructure that needs a UI home. The placement below is a recommendation, not a spec requirement — reasonable to adjust if the product team wants it somewhere else.

---

## Recommended entry point: Settings/More

Add a **"Product cost prices"** row to `MoreScreen` (`settings-flow-screens.md`'s Screen 1), between "Team & Roles" and "Subscription/Billing" — an occasional setup task fits the settings hub better than a primary tab. Every plan can see this row (cost price isn't plan-gated server-side); no entitlement check needed to show it.

**Also worth a contextual entry point:** if `ai-flow-screens.md`'s `AskAiScreen` returns a profit/restock answer that mentions excluded items (missing cost price), show an inline "Set cost prices" link in that chat bubble pointing straight here — closes the loop for a merchant who wouldn't otherwise know this screen exists.

---

## Screen 1 — `ProductCostPricesScreen`

**On load:** `GET /products`.

**List:** title, SKU (or "No SKU" if null — don't show a blank), stock quantity, and the cost price itself — right-aligned, showing "Not set" (not "$0.00" or a blank) when `cost_price` is `null`. A search/filter-by-title input is reasonable for teams with a large catalog, purely client-side (no server-side search param on this endpoint).

**Tap a row** → inline edit or a small sheet: a single numeric input pre-filled with the current `cost_price` (empty if `null`), a "Clear" action distinct from just emptying the field (see below), Save/Cancel.

**Save:** `PUT /products/{id}/cost-price {cost_price: <number>}`. Update the row optimistically, revert on 422.

**Clear:** a distinct control (not just "save an empty field") that sends `{cost_price: null}` explicitly — make the difference between "I haven't entered a number" (leave the field, don't save) and "I want to remove the cost price I previously set" (tap Clear, which does save, sending `null`) obvious in the UI, since these produce different server states and the API doc calls out that this distinction matters.

**Read-only for `viewer`/`agent` roles:** the list itself has no role restriction (`GET /products` just requires auth), but editing does (`owner`/`manager` only, per the API doc) — hide the edit affordance entirely for restricted roles rather than letting them tap into an edit flow that will 403/422 on save.

**Empty state:** "No products synced yet" with a note to check store connections — this list is entirely populated by syncing, never manually created (per the API doc's "not a catalog you create from this app" note).

**Bulk entry:** not supported by this API (no batch/CSV endpoint) — if a seller has many products, this is a genuinely tedious one-at-a-time flow today; worth flagging to product/design as a possible future gap rather than trying to fake batch behavior with sequential individual calls dressed up as one action.
