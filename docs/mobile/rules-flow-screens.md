# StockBeat Mobile — Rules Screens

Tab 2 in the bottom nav (Feed · **Rules** · Inbox · More, Plan §4.10). Depends on `orders-feed-screens.md` only loosely — a merchant can visit this tab any time after onboarding, there's no hard sequencing requirement like Auth→Connections→Feed had. Pair with `rules-api-reference.md` for exact shapes — **its "real bug fixed this pass" callout matters for Screen 2 below.**

---

## Screen 1 — `RulesListScreen`

**On load:** `GET /rules`.

**Content:** one row per rule — name, trigger (human-readable label, not the raw `trigger` key — build a lookup table from the trigger catalogue in the API reference), enabled/disabled toggle, and a small "last fired" hint if you want to also fetch `GET /rules/{id}/executions` per row (optional, costs N+1 requests — consider only fetching on-demand when a row expands, not eagerly for the whole list).

**Enabled toggle:** flips instantly via `PUT /rules/{id} {enabled: false}` (optimistic update, revert on failure).

**No delete action** — per the API reference, there's no delete endpoint. Don't build a swipe-to-delete gesture; disabling is the only "turn this off" affordance that exists.

**Empty state** (Plan §4.10: "empty rules shows 3 one-tap template rules"): show 2–3 pre-built starter suggestions as tappable cards — e.g. "Notify me for orders over $100," "Alert me when stock is low," "Daily summary at 8am" — tapping one pre-fills Screen 2's form rather than making the merchant start from a blank condition-tree editor. This is explicitly called out in Plan's UX principles as the empty-state pattern for this screen.

**Free-plan / at-limit state:** if `entitlements.limits.max_rules` is `0` (Free) or the rule count has reached a numeric limit, the "+ New rule" button should open the upgrade paywall directly instead of Screen 2 — the server would 422 anyway (see API reference), don't make them fill out a form to discover that.

**On tap of a rule row:** `RuleEditScreen` (Screen 2, pre-filled).
**On tap of "+ New rule":** `RuleEditScreen` (Screen 2, blank) — or, if you build the AI entry point (see Screen 3), offer a choice between "Build with AI" and "Build manually" first.

---

## Screen 2 — `RuleEditScreen` (create or edit)

**Params:** `rule_id` (optional — absent means create).

### Trigger picker
A list/grid of the 13 triggers (human labels + short descriptions, from the API reference's catalogue table). **Filter or badge the gated ones** (`order_spike`, `refund_spike`, `ai_insight`) based on `entitlements.limits.advanced_triggers_enabled`/`ai_proactive_insights_enabled` — show them with a "Premium" lock badge rather than hiding them entirely (a Starter merchant should see what they're missing, per Plan's conversion-mechanics philosophy elsewhere in the app).

### Condition builder — only for triggers that use conditions
Not every trigger needs conditions (`new_order`, `digest`, `ai_insight` typically don't; `high_value_order` is *defined by* its conditions — there's no separate "value" field on the trigger itself, the threshold lives entirely in `conditions`).

**Build this as a fixed-choice UI, never free text:**
- Field: a dropdown of the 10 real field names (with human labels — "Order total" not `total`).
- Operator: a dropdown of the 8 real word-based operators (human labels — "is greater than" not `gt`) — **this is the exact spot the pre-existing bug lived in**. If you're tempted to show operator buttons like "＞" "≥" "=", map their *tap* to the real word string (`gt`, `gte`, `eq`) before ever touching the API — never send a symbol.
- Value: input type depends on field (numeric keyboard for `total`/`quantity`, a platform picker for `channel`, a store picker for `store`, free text for `sku`/`product`/`customer_country`/`shipping_method`/`tag`, a toggle for `repeat_buyer`).
- Support building multiple conditions and choosing `all` (AND) vs `any` (OR) grouping — even a simple version (all conditions in one `all` list, no nested groups) covers the large majority of real use cases and matches every example in the API reference.

### Trigger-specific controls
Show extra fields based on the selected trigger, per the API reference's per-trigger `controls` table — e.g. `unfulfilled_after_x` needs a "how many hours" number input, `digest` needs a frequency/time/day picker, `low_stock` needs a threshold number input. Don't show a generic "controls" JSON editor — map each real field to a real input.

### Universal controls (any trigger)
- **Quiet hours** — start/end time pickers + timezone (default to the device's, matching the pattern already established in `auth-flow-screens.md`'s `ProfileSetupScreen`).
- **Cooldown** — "don't fire more than once every N minutes" number input.

### Actions
Multi-select chips: Push, Email, SMS, Notify a team member (opens a member picker, needs `GET /team` — sets `user_id`), Auto-tag (opens a text input for the tag — sets `tag`). At least one required.

### Sound picker
Only shown/relevant when Push is selected — 4 options (`default`/`cha_ching`/`alert`/`chime`), let the merchant preview each (play the actual bundled sound file on tap, client-side, no API call needed for preview).

### On submit
`POST /rules` (create) or `PUT /rules/{id}` (edit) with the assembled payload. Handle 422s by mapping `errors["conditions.all.0.operator"]`-style nested keys back to the specific condition row in your UI (parse the numeric index out of the key) — don't just show a generic "validation failed" toast, point at the actual row.

### "Test this rule" button
Visible once a rule exists (i.e., not on a brand-new unsaved form) — `POST /rules/{id}/test`. **This is a real send**, not a preview — say so in the UI ("This will really send a notification now"). Show the returned `actions_result` per action type with its real status (see API reference's status-value table) — a good pattern is a small result sheet listing each action and a colored status chip, not just a single "test successful" toast, since e.g. `quota_exceeded` or `insufficient_credit` are genuinely useful things for the merchant to see.

---

## Screen 3 — AI Rule Builder entry point (Pro+)

**Reached from:** "+ New rule" → "Build with AI" (an alternative to the manual Screen 2 flow, not a replacement).

**Content:** a single large text input — "Describe the rule you want" — with placeholder examples ("Notify me by text whenever an eBay order is over $200," "Alert me when any product drops below 5 in stock"). Gate this entry point on `entitlements.limits.ai_rule_builder_enabled`; if false, show it locked with an upgrade prompt rather than omitting it (same "show what you're missing" philosophy as the trigger picker).

**On submit:** `POST /assistant/rule-draft {prompt}`. Response includes `valid` (bool) and, if invalid, `errors` in the same nested-key shape as a normal rule validation failure.

**Then:** navigate to `RuleEditScreen` (Screen 2) **pre-filled with the parsed draft** — trigger, conditions, actions, controls all populated from `data.draft`. The merchant reviews/edits exactly like any other rule before submitting — **never auto-save the AI's draft directly**, always route through the same manual review screen. If `valid: false`, still pre-fill the form with whatever was parsed and let the merchant fix the flagged fields (using `data.errors` the same way you'd handle a real 422) rather than discarding the attempt and making them start over.

---

## Edge case: a rule referencing a team member who's since left

`actions[].user_id` for a `notify_member` action could point to a team member removed after the rule was created. The API doesn't proactively clean this up — if you're showing "notify [name]" in the rule summary, resolve the name from a live `GET /team` call rather than caching it, and handle the case where the id isn't found (show "a removed team member" rather than crashing on a missing lookup).

## Edge case: `ai_insight` rules have nothing to configure

If a merchant enables the `ai_insight` trigger, the condition builder and trigger-specific controls sections should both be hidden — this trigger is entirely server-detected (Plan §4.12's Proactive AI Insights), there is nothing for the merchant to define beyond which actions to take and quiet hours/cooldown. Don't show an empty "add condition" prompt for a trigger that structurally can't use one.
