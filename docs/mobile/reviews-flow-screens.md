# StockBeat Mobile — Reviews Screen

Pair with `reviews-api-reference.md` — **read its opening note first**: reply support is eBay-only in v1, WooCommerce reviews are read-only, and this whole feature (list + reply) is new as of 2026-07-26 — there was no way to even view reviews from the app before this.

---

## Entry point: Settings/More

Row 7 in `MoreScreen` (`settings-flow-screens.md`'s Screen 1), right after "Payouts" — same "occasional glance" reasoning as Products/Customers/Payouts, not a primary tab.

**Also worth a contextual entry point:** a `negative_review` rule notification (`notifications-flow-screens.md`) should deep-link straight to the specific review in this list, rather than making the seller hunt for it — same pattern already recommended for low-stock notifications in `products-flow-screens.md`.

---

## Screen — `ReviewsScreen`

**On load:** `GET /reviews`.

**List:** reviewer name, star rating (1–5, though eBay negative feedback always renders as 1 — see the API doc's note on why), review text, relative time. A small channel icon per row (cross-reference `connection_id` against `GET /connections`) helps a seller tell an eBay review from a WooCommerce one at a glance, which matters here because of the reply-availability difference below.

**Reply control — eBay reviews only.** Check the review's connection `platform` (via `GET /connections`) before rendering a "Reply" button on a row; WooCommerce reviews render read-only with no reply affordance at all — don't show a disabled/greyed-out button either, just omit it entirely, same "hide, don't dead-end" rule the cost-price screen already follows for role restrictions.

**Reply flow:** tapping "Reply" opens a simple composer (single text field, max 2000 chars, Send/Cancel) — `POST /reviews/{id}/reply {body}`. On success, show the review as "Replied" (e.g. a small checkmark or "You replied" label) so the seller doesn't accidentally send a second reply — the API itself doesn't track or block duplicate replies, so this is purely a client-side UX safeguard, not a server-enforced rule.

**Read-only for `viewer`/`agent` roles:** the list itself has no role restriction, but replying does (`owner`/`manager` only) — hide the reply control for restricted roles, same convention as every other capability-gated action in this app.

**Empty state:** "No reviews yet" — this list is entirely populated by syncing (currently WooCommerce + eBay only), never manually created.
