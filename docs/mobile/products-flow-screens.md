# StockBeat Mobile — Product Cost Prices & Inventory Screens

Pair with `products-api-reference.md` — **read its opening note first**: this isn't a Plan §4 bottom-nav screen, it's Phase B infrastructure that needs a UI home. The placement below is a recommendation, not a spec requirement — reasonable to adjust if the product team wants it somewhere else.

---

## Recommended entry point: Settings/More

Add a **"Products" (or "Inventory")** row to `MoreScreen` (`settings-flow-screens.md`'s Screen 1), between "Team & Roles" and "Subscription/Billing" — an occasional setup/maintenance task fits the settings hub better than a primary tab. Every plan can see this row (neither cost price nor stock editing is plan-gated server-side); no entitlement check needed to show it.

**Also worth a contextual entry point:** if `ai-flow-screens.md`'s `AskAiScreen` returns a profit/restock answer that mentions excluded items (missing cost price), show an inline "Set cost prices" link in that chat bubble pointing straight here — closes the loop for a merchant who wouldn't otherwise know this screen exists. **Same idea for stock (added 2026-07-26):** a `low_stock` rule notification (`notifications-flow-screens.md`) or feed low-stock badge should deep-link straight to this screen, ideally scrolled/filtered to the specific product that triggered it, rather than making the seller search for it manually.

---

## Screen 1 — `ProductCostPricesScreen` (a.k.a. "Inventory" — one screen, not two)

**On load:** `GET /products`.

**List:** a leading thumbnail (`image_url`, added 2026-07-31), title, SKU (or "No SKU" if null — don't show a blank), stock quantity, and the cost price itself — right-aligned, showing "Not set" (not "$0.00" or a blank) when `cost_price` is `null`. When `image_url` is `null` (common — see the API doc), show a plain placeholder tile, not a broken-image icon or a layout gap; don't block the row on the image loading. A search/filter-by-title input is reasonable for teams with a large catalog, purely client-side (no server-side search param on this endpoint).

**Tap a row (cost price)** → inline edit or a small sheet: a single numeric input pre-filled with the current `cost_price` (empty if `null`), a "Clear" action distinct from just emptying the field (see below), Save/Cancel.

**Save:** `PUT /products/{id}/cost-price {cost_price: <number>}`. Update the row optimistically, revert on 422.

**Clear:** a distinct control (not just "save an empty field") that sends `{cost_price: null}` explicitly — make the difference between "I haven't entered a number" (leave the field, don't save) and "I want to remove the cost price I previously set" (tap Clear, which does save, sending `null`) obvious in the UI, since these produce different server states and the API doc calls out that this distinction matters.

**Stock quantity control (added 2026-07-26, Plan §4.13):** a compact stepper (minus / number / plus) on the same row, next to the cost price — not a separate screen, since it's the same underlying product list just with a second editable field. **Only show the stepper when the row's connection platform is `shopify` or `woo`** (cross-reference `connection_id` against `GET /connections`, same check the API doc describes) — every other channel doesn't support it yet, so don't render a control that will 422 on save. A "Save" affordance appears once the number changes; **`PUT /products/{id}/stock {quantity: <int>}`**, optimistic update, revert on failure. Rows below a merchant-configured low-stock threshold get the same visual flag already used elsewhere for low-stock (badge/left-edge accent) so this screen doubles as "here's what to fix" after a low-stock alert, not just a static list.

**Read-only for `viewer`/`agent` roles:** the list itself has no role restriction (`GET /products` just requires auth), but editing does (`owner`/`manager` only, per the API doc, for both cost price and stock) — hide both edit affordances entirely for restricted roles rather than letting them tap into an edit flow that will 403/422 on save.

**Empty state:** "No products synced yet" with a note to check store connections — this list is entirely populated by syncing, never manually created (per the API doc's "not a catalog you create from this app" note). See the paired Stitch mockup "StockBeat - Inventory Empty State."

**Bulk entry (cost price only):** a real batch endpoint exists now (`PUT /products/cost-prices`, added 2026-07-22 — this doc previously flagged its absence as a gap). Worth an explicit "Edit multiple" mode: a checkbox/multi-select on the list, entering a mode where each selected row's cost-price field becomes directly editable inline, then a single "Save all" button collects every changed row into one `updates` array and sends one request. **Remember it's atomic** — if the save fails (e.g. a product was deleted mid-session by a sync elsewhere), none of the batch's edits are applied; show the 422 plainly and let the seller retry rather than silently losing part of their work. No CSV import/export — this is still a manual per-product entry flow, just no longer a per-*request* one. **No bulk endpoint exists for stock quantity** — that stays one row at a time; don't build a bulk stock UI expecting a batch call that isn't there.

**Naming note:** the two Stitch mockups in this project ("StockBeat - Product Cost Prices" and "StockBeat - Inventory") were generated as separate illustrative passes and show each field in isolation — in the actual build this is **one screen** with both controls on the same row, not two destinations a seller navigates between.
