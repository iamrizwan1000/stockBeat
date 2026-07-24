# StockBeat Mobile — Usage History Screens

Not a bottom-nav destination — a drill-down reached from the Subscription screen (`settings-flow-screens.md`'s Screen 4, `SubscriptionScreen`). Pair with `usage-api-reference.md` for exact request/response shapes.

## Where this sits in the app's navigation tree

Both screens are pushed onto the **Settings/More stack** — same stack `settings-flow-screens.md`'s screens already live in, not a new tab, not a modal, not a root-level screen. Full chain from the bottom tab bar down:

```
Tab 4 "More" (bottom nav)
  → MoreScreen                    (settings-flow-screens.md Screen 1 — tap "Subscription / Billing")
    → SubscriptionScreen          (settings-flow-screens.md Screen 4 — tap "View usage details →")
      → UsageSummaryScreen        (this doc, Screen 1 — tap any of the 3 channel cards)
        → UsageDetailScreen       (this doc, Screen 2)
```

Implementation-wise, this means: whichever stack navigator already contains `MoreScreen`/`SubscriptionScreen`/`TeamScreen`/etc. is where `UsageSummaryScreen` and `UsageDetailScreen` get registered too — no new navigator, no new tab bar entry, no change to the root navigation structure at all. Back button behavior is a plain stack pop at every level (Detail → Summary → Subscription → More Menu), same as every other drill-down in this app (e.g. Team → Invite Member Sheet, Rules List → Rule Edit).

---

## Entry point — add to the existing `SubscriptionScreen`

Inside the "Usage this month" section `settings-flow-screens.md` already specifies (the one showing `sms_balance`/`ai_questions_remaining`/`emails_remaining`), add a single **"View usage details →"** link below those three rows. Tapping it calls `GET /usage/summary` and pushes `UsageSummaryScreen` (below) onto the same navigation stack — this is a forward push within the Settings/More stack, **not** a new tab and not a modal.

This is the only change to `SubscriptionScreen` — nothing else on that screen is affected.

---

## Screen 1 — `UsageSummaryScreen`

**On load:** `GET /usage/summary`.

**Content:** three stacked cards, one per channel:

1. **SMS** — "`{balance}` credits remaining" as the primary figure, a progress bar showing `pct_used`% against `plan_monthly_allotment` ("2% of this month's 100-credit allotment used"), and a small 7-day sparkline preview built from the last 7 entries of `daily`.
2. **AI Questions** — "`{remaining}` of `{limit}` remaining," same progress-bar/sparkline treatment against `pct_used`.
3. **Email Alerts** — "`{remaining}` of `{limit}` remaining," same treatment.

**Unlimited/locked variant:** if a channel's `limit` (or, for SMS, `plan_monthly_allotment`) is `null` or `0`, `pct_used` comes back `null` — render that card without a progress bar (just the raw balance/remaining figure, no percentage claim).

**Quota-warning state:** when `quota_warning` is `true` for a channel, that card's progress bar renders in an amber/red accent instead of the normal brand color, with a small warning icon next to the remaining-count, and an inline "Buy more" / "Upgrade" button pair at the bottom of that specific card only — the other two cards render normally regardless.

**On tap of a card:** push `UsageDetailScreen` (below), passing which channel was tapped (`sms` / `ai_questions` / `emails`) as a param so its tab opens pre-selected. Pass the already-fetched `GET /usage/summary` response forward rather than re-fetching — the data doesn't meaningfully change in the few seconds between screens.

---

## Screen 2 — `UsageDetailScreen`

**Params:** `channel` (`"sms"` | `"ai_questions"` | `"emails"`), and the `GET /usage/summary` response passed forward from Screen 1 (no new network call on open).

**Header:** a segmented tab control — SMS / AI Questions / Email — with the passed-in `channel` pre-selected. **Switching tabs is purely client-side** (re-render from data already in hand); it never triggers a new request and never pushes/pops the navigation stack.

**Content, per selected tab:**
- The same big remaining/balance figure and progress bar as its card on Screen 1.
- The raw numbers below it ("12 used of 150 this month" for AI Questions; "2 used of 100 allotted" for SMS, phrased around the allotment rather than the wallet balance since that's the number this screen's percentage is about).
- A **30-day bar chart** built from that channel's `daily` array — 30 bars, x-axis showing sparse date labels (e.g. every 5th day), the current day's bar visually highlighted/outlined. Zero-count days render as empty/baseline bars, not omitted — `daily` is already zero-filled, don't collapse gaps.

**Quota-warning banner:** when the active tab's `quota_warning` is `true`, show a banner above the chart — "You've used `{pct_used}`% of this month's `{channel label}`" with "Buy more" (SMS/AI only — no email top-up product exists) and "Upgrade plan" buttons. Hidden entirely when `quota_warning` is `false`.

**Back button:** pops back to `UsageSummaryScreen`, which in turn pops back to `SubscriptionScreen` — standard stack pop at every level, no special-casing.

---

## Edge case: SMS's monthly allotment now auto-renews (fixed 2026-07-24)

Per `usage-api-reference.md`'s callout, the monthly SMS grant (`GrantMonthlySmsCreditsAction`) now actually runs — a team's plan allotment lands in `sms.balance` once per calendar month (immediately at trial start and on purchase/renewal, with a daily job as a safety net). Nothing to build for this specifically — `balance` and `pct_used` already come back correct from `GET /usage/summary`, same as before this was fixed. One thing worth knowing if a support ticket ever asks "why didn't my balance go up exactly on my renewal date": the grant only *adds* the allotment to whatever's already in the wallet rather than tracking "monthly" credit separately from top-up credit, so an unused monthly grant isn't strictly prevented from carrying into next month the way Plan §5's copy describes — not something to explain or surface in the UI, just don't be surprised by a balance that's higher than the plan's stated allotment would suggest on its own.
