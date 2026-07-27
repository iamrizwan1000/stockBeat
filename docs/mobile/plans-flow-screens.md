# StockBeat Mobile — Plans / Compare Plans Screen Reference

Added 2026-07-28. Closes a real gap: the app has no screen anywhere that shows a seller what each tier actually includes — `SubscriptionScreen` (`settings-flow-screens.md` Screen 4) only shows the seller's *own current* plan, and "Upgrade" opens RevenueCat's native paywall sheet directly, whose copy is configured inside RevenueCat's own dashboard, not this codebase. This doc is the content spec for an in-app "Compare plans" screen/section to show **before** that handoff, so a seller sees an accurate, StockBeat-controlled comparison first.

**Where this lives in the flow:** add a "Compare plans" entry point from `SubscriptionScreen`'s upgrade CTA (or other paywall trigger points — the second-store trigger, locked-teaser notifications, etc., still an open checklist item per Plan.md's "Paywall screens" line, not yet built). Tapping "Upgrade to X" from this screen still opens RevenueCat's native purchase sheet exactly as documented in `settings-flow-screens.md` — this screen is informational, not a purchase surface itself.

## Verified against real data, not just Plan.md prose

Every number below was cross-checked directly against the live `plan_limits` database table (2026-07-28) — not copied from Plan.md's §5 table alone, since that's prose that can drift from what's actually seeded/admin-edited. If you need to re-verify before shipping, the values live in `plans`/`plan_limits` and are admin-editable at `/admin` → Plans & Limits; this screen's copy should be treated as a snapshot that needs re-checking if those change materially, not something to hardcode and forget.

**Do not hardcode these numbers as UI constants without a plan to re-sync them.** The actual entitlement *enforcement* is 100% DB-driven and dynamic per `GET /me` — this screen's copy is marketing/informational text describing that enforcement, so it can silently drift out of sync with the real limits if an admin changes them and nobody updates the app copy. Ideally, pull the numeric parts (`max_stores`, `sms_monthly`, `email_monthly`, `history_days`, `team_seats`, `ai_questions_monthly`) live from each plan's limits rather than baking them into strings, if there's a way to fetch another team's plan limits for display (there isn't one today via `/me`, which only returns the *caller's* plan) — otherwise, whoever maintains this screen needs a process to re-check these numbers periodically against the admin panel.

## Every plan includes (not a tier differentiator — don't repeat these per-card)

- Unified order feed & search
- Quick actions — fulfill, track, refund
- Invoices & packing slips
- Order timeline
- Saved filters
- Bulk cost-price editing (`PUT /products/cost-prices` — confirmed no plan-limit gate in code, only a `team.role:owner,manager` check)
- Inventory & customer view

## Free — $0

- 1 connected store
- New-order push + daily digest
- 25 email alerts/mo
- 7 days of order history

## Starter — $5.99/mo

- Up to 3 stores
- 5 custom rules — low stock, reviews, back-in-stock & more (`max_rules: 5`)
- Notification priority per rule (`Notification priority` is available Starter+, not Pro-exclusive — confirmed no separate plan-limit flag gates it, any team that can create a rule at all gets it)
- Bulk tag/cancel orders (`bulk_actions_enabled: true`)
- 20 SMS + 250 email/mo · 30 days of history
- AI Assistant — 30 questions/mo (`ai_questions_monthly: 30`)

## Pro — $17.99/mo (also $172.99/yr, save 20%)

- Up to 10 stores
- Unlimited custom rules (`max_rules: null`)
- Unified inbox, payouts & review replies (`inbox_enabled: true` — gates Reviews, Inbox, and Payouts alike)
- Full analytics + monthly business report (`analytics_level: full`, `widgets_enabled: true`)
- 100 SMS + 1,000 email/mo · 3 team seats
- AI Assistant — 150 questions/mo + natural-language rule builder (`ai_rule_builder_enabled: true`)
- 7-day free trial (note: the trial itself actually grants **Premium**, not Pro — see below)

**Do not list "custom sound per rule" as a Pro-exclusive perk.** Checked directly in `StoreRuleRequest`/`UpdateRuleRequest`/`CreateRuleAction`/`UpdateRuleAction` — the `sound` field has no plan-tier gate at all. Any team that can create a custom rule (Starter+) can already set one. Plan.md's own pricing table implies this is Pro+ only; that's stale/unenforced documentation, not real behavior — don't repeat the mistake in the app.

## Premium — $44.99/mo (also $429.99/yr, save 20%)

- Unlimited stores
- Everything in Pro, plus order & refund spike alerts (`advanced_triggers_enabled: true` — the only two triggers actually gated Premium-only)
- 500 SMS + 5,000 email/mo · 10 team seats
- AI Assistant — 500 questions/mo + proactive AI insights (`ai_proactive_insights_enabled: true`)
- Priority support

## The 7-day free trial is Premium, not "whichever plan you pick"

Every new team gets `GrantTrialSubscriptionAction`'s trial on signup — `plan_key: premium`, full Premium entitlements, for 7 days (admin-tunable via `plan_limits.trial_days` on the Premium plan). This is deliberate ("full-featured trial taken literally" per Plan §6.3) so a trialing seller experiences everything, including advanced triggers, before picking a tier. **Don't word any trial messaging as "try Pro free" or similar** — it's the full top tier, and underselling it in copy would be inaccurate in the other direction.

An admin can also manually extend a trial from the Customer Detail screen (`ExtendTrialAction`) — this always resets `plan_key` to Premium too (fixed 2026-07-28: it previously could leave a lapsed paid subscription's old, lower `plan_key` in place). Either way, `subscription_status: "trial"` in `/me`'s entitlements always means full Premium access until `trial_ends_at`.

## Keep this in sync with RevenueCat's paywall

The actual purchase screen (RevenueCat's native paywall sheet, opened from `SubscriptionScreen`) has its own feature-list copy configured entirely inside RevenueCat's dashboard — a separate system this codebase has no visibility into or control over. **Whoever authors that copy should use this same verified list**, so a seller doesn't see one feature set on this in-app comparison screen and a different one on the actual purchase sheet seconds later. There's no automated way to keep these two in sync today; it's a manual-parity responsibility.
