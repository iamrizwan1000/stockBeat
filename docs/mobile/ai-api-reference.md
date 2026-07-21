# StockBeat Mobile — AI Assistant API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Plan §4.12. **Not a bottom-nav tab** — this is a cross-cutting feature reachable from multiple places: a persistent "Ask AI" entry, a suggested-questions chip row on the Feed/Business Overview header, an "Ask AI about this" affordance on order detail and on notifications, and the natural-language input on the Rules create screen (`rules-flow-screens.md` already cross-references the rule-draft endpoint — this doc is its full reference).

**Any team role can call every endpoint here** — unlike most write endpoints in this API, nothing under `assistant/*` is restricted to `owner`/`manager`. Asking a question or drafting a rule is read-only until the seller explicitly submits the draft to `POST /rules`, which *does* still require `owner`/`manager` (`rules-api-reference.md`).

---

## Two modes — read this before building anything

Every call to `POST /assistant/ask` is one of exactly two modes, and **you must pass `mode` explicitly** — it's never inferred from the question text:

| Mode | What it answers | Plan gating | Counts against quota? |
|---|---|---|---|
| `"data"` (default if omitted) | Real questions about the seller's own store: sales, orders, inventory, profit/margin, restock timing, top products | `entitlements.limits.ai_enabled` must be `true` — **locked entirely on Free** | Yes — see quota below |
| `"help"` | How-to questions, plan/billing questions, connection troubleshooting ("why isn't my store syncing") | **None — every plan, including Free** | No — never touches the quota |

**Why this matters for your UI:** don't build one generic "Ask AI" box that silently guesses which mode to use. The clean pattern:
- A **Help entry point** (in Settings/More, per `settings-api-reference.md`, or a "?" icon) always sends `mode: "help"` — safe to expose to every user on every plan, never shows a paywall.
- A **Data Copilot entry point** (Feed header chip row, "Ask AI about this order", the main "Ask AI" button) always sends `mode: "data"` — check `entitlements.limits.ai_enabled` *before* showing this entry point at all on Free; if you show it anyway and the team is on Free, the call 422s with a clear upgrade message (see errors below), which is an acceptable fallback but a worse experience than not showing the button.

If a `data`-mode question is really a "how do I..." question, the model itself will say so in its answer (its system prompt tells it not to guess business figures it can't see) rather than the server rejecting it — that's a normal, successful response, not an error.

---

## `POST /assistant/ask`

**Requires auth.**
```json
{ "question": "What were my top products yesterday?", "mode": "data", "conversation_id": null }
```

| Field | Rules |
|---|---|
| `question` | required, string, max 1000 chars |
| `mode` | optional, `"data"` or `"help"` — defaults to `"data"` if omitted (so **always send it explicitly**, don't rely on the default matching your entry point's intent) |
| `conversation_id` | optional, integer — omit/`null` to start a new conversation, or pass an existing one's id to continue it with follow-up context |

**Success — 200:**
```json
{ "success": true, "message": null, "data": { "conversation": {
  "id": 5,
  "title": "What were my top products yesterday?",
  "messages": [
    { "id": 1, "role": "user", "content": "What were my top products yesterday?", "tool_calls": null, "created_at": "2026-07-22T01:00:00.000000Z" },
    { "id": 2, "role": "assistant", "content": null, "tool_calls": [{"id": "call_1", "name": "get_top_products", "arguments": {"range": "today"}}], "created_at": "2026-07-22T01:00:01.000000Z" },
    { "id": 3, "role": "tool", "content": "{\"top_products\":[...]}", "tool_calls": [{"id": "call_1", "name": "get_top_products"}], "created_at": "2026-07-22T01:00:01.000000Z" },
    { "id": 4, "role": "assistant", "content": "Your top product yesterday was...", "tool_calls": null, "created_at": "2026-07-22T01:00:02.000000Z" }
  ],
  "created_at": "2026-07-22T01:00:00.000000Z", "updated_at": "2026-07-22T01:00:02.000000Z"
} } }
```

### ⚠️ Real thing to get right — `messages` includes internal turns, not just the visible conversation

`messages` is the **full, raw model transcript**, not a curated chat history — it includes the model's internal tool-call requests (`role: "assistant"` with `content: null` and a populated `tool_calls`) and the raw tool results fed back to it (`role: "tool"`, `content` is a JSON string of the tool's return value, not meant for display). If you render every message as a chat bubble, the user sees a garbled internal tool-calling trace instead of a clean Q&A. **Filter to what a chat UI actually wants:**
- Always show `role: "user"` messages as the user's own bubble.
- Show `role: "assistant"` messages **only when `content` is non-null** (the final answer) — skip any assistant message where `content` is `null`/empty, those are intermediate tool-call requests with nothing to display.
- Never show `role: "tool"` messages — always internal, raw JSON, not written for a human reader.

A single "ask" can produce several tool round-trips (up to 5) before the final answer — expect more than 2 messages to be appended per call, and only the last non-null-content assistant message is "the answer" for that turn.

### Errors

| Status | Scenario | Message |
|---|---|---|
| 422 | `mode: "data"` but `ai_enabled` is `false` | `"The AI Data Copilot isn't available on your current plan. Upgrade to ask questions about your store, or use App Help for how-to/billing questions."` under `errors.question` — paywall trigger |
| 422 | `mode: "data"` monthly quota exhausted | `"You've used all {N} AI questions included in your plan this month. Upgrade or wait for next month's reset."` under `errors.question` |
| 422 | Profile setup incomplete | `"Complete profile setup before using the AI Assistant."` |
| 404 | `conversation_id` doesn't exist or isn't your team's | Standard not-found |
| 422 (via `AiProviderException`, surfaces as a generic failure) | The model couldn't produce a usable answer after 5 tool rounds | `"The AI Assistant could not produce an answer after several tool calls — try rephrasing the question."` — show as a normal error toast with a retry, not a crash |

### The question quota (Data Copilot only)

`entitlements.limits.ai_questions_monthly` (`auth-api-reference.md`) is the plan's monthly cap — `null` means unlimited. **`entitlements.ai_questions_remaining`** (added 2026-07-22, from `GET /me` — see `settings-api-reference.md`'s billing section) is the authoritative, server-computed remaining count for the current calendar month, already netting the plan allotment against usage and any top-up credit purchased this month; `null` means unlimited. Use this for any "questions remaining" indicator rather than counting successful asks client-side — it's shared correctly across every device on the team, not a per-device guess.

**AI question top-up packs exist** (added 2026-07-22, closing what was previously a real gap — only SMS had a purchasable top-up before): `GET /me`'s `ai_topup_packs` array, purchased via the RevenueCat SDK exactly like SMS packs — full purchase-flow detail in `settings-api-reference.md`'s billing section, since that's also where the Subscription screen's top-up sheet lives. When a `mode: "data"` question 422s with the quota-exhausted message below, that's the natural moment to offer a deep link straight into that top-up sheet (`settings-flow-screens.md`'s `SubscriptionScreen`) — don't just show the error and dead-end.

---

## `GET /assistant/conversations`

**Requires auth.** Most recent 50 conversations, most recent first, **without** their messages loaded (build a history list from `id`/`title`/`updated_at`, then fetch full messages on tap).
```json
{ "success": true, "message": null, "data": { "conversations": [
  { "id": 5, "title": "What were my top products yesterday?", "messages": [], "created_at": "...", "updated_at": "..." }
] } }
```
`title` is auto-generated from the first question (truncated to 60 chars) — there's no rename endpoint, treat it as read-only display text.

## `GET /assistant/conversations/{id}`

**Requires auth.** Same shape as the `ask` response's `conversation` object, with `messages` fully loaded — apply the same message-filtering rule above when rendering. **404** if it's not your team's conversation.

---

## What the Data Copilot can actually answer

Every answer is grounded in a real tool call over the team's own data — never a free-associated guess (Plan §4.12's core guarantee). Useful for building an honest "suggested questions" chip row (there's no server endpoint for suggestions — this is static client-side copy, but it should only suggest things the assistant can actually answer):

| Real capability | Example question |
|---|---|
| Sales summary over a range | "How did I do today?" |
| Top products | "What are my best sellers this week?" |
| Order lookup/filtering | "Show me unfulfilled orders from eBay" |
| Low-stock products | "What's running low on stock?" |
| Profit/margin | "What was my profit yesterday?" — **honest partial-coverage caveat below** |
| Restock timing | "What should I restock soon?" — same caveat |
| Connection health (`help` mode too) | "Why isn't my Shopify store syncing?" |
| Account/plan status (`help` mode too) | "What plan am I on?", "How much SMS credit do I have left?" |

**Profit and restock answers are deliberately, honestly partial** — profit only covers order line items whose product has a seller-entered `cost_price` set (`products-api-reference.md`-equivalent, see `PUT /products/{id}/cost-price`), and restock recommendations only cover products with real recent sales velocity. The model is instructed to say so explicitly when data is missing rather than implying a complete figure — **don't post-process or "clean up" an answer that mentions a caveat like this, show it verbatim**, it's there on purpose.

**Never in scope, and the model will say so rather than guess:** ad spend, discount/coupon totals on non-WooCommerce stores, anything not tracked by this app at all.

---

## Building a rule from plain English

### `POST /assistant/rule-draft`

**Requires auth.** Requires `entitlements.limits.ai_rule_builder_enabled` (Pro+) — check it client-side before showing this entry point, same paywall pattern as elsewhere.

```json
{ "prompt": "notify me by text whenever an eBay order is over $200" }
```
`prompt`: required, string, max 500 chars.

**Success — 200:**
```json
{ "success": true, "message": null, "data": {
  "draft": {
    "name": "High-value eBay order",
    "trigger": "high_value_order",
    "conditions": { "all": [
      { "field": "channel", "operator": "eq", "value": "ebay" },
      { "field": "total", "operator": "gt", "value": 200 }
    ] },
    "actions": [ { "type": "sms" } ],
    "controls": {}
  },
  "valid": true,
  "errors": null,
  "trigger_is_advanced": false
} } }
```

**This never saves anything** — it's pure text-to-structure translation. The response `draft` is in the **exact same shape** `POST /rules` expects (`rules-api-reference.md`). The intended flow: show the draft pre-filled into the ordinary rule-creation form fields (name/trigger/conditions/actions/controls, all editable), let the merchant review and adjust, then submit through the normal `POST /rules` — **treat this as a form-prefill helper for the same create-rule screen, not a separate "AI rules" flow with its own save button.**

**`valid: false` is a real, expected outcome, not a bug** — the model can produce a plausible-looking draft that still fails real validation (e.g. an ambiguous prompt, a trigger requiring Premium the team doesn't have, a hallucinated condition field). When `valid` is `false`, `errors` mirrors the exact shape `POST /rules`'s own 422 would return (`{"conditions.all.0.field": ["..."]}` etc.) — **surface these inline on the pre-filled form fields**, the same error-rendering logic the manual create-rule form already needs for its own 422s works unchanged here, since it's the identical validator.

`trigger_is_advanced`: `true` if the drafted trigger is `order_spike`/`refund_spike` (Premium-gated, `rules-api-reference.md`'s trigger catalogue) — useful for showing an inline "this needs Premium" hint on the pre-filled form even when `valid` happens to be `true` for a team that has that gate (i.e., it's informational, not itself a blocker — `POST /rules` re-checks the real gate at save time regardless).

**Errors:**
| Status | Scenario | Message |
|---|---|---|
| 422 | `ai_rule_builder_enabled` is `false` | `"The AI rule builder requires the Pro plan or higher."` under `errors.prompt` |
| 422 | Profile setup incomplete | `"Complete profile setup before using the AI Assistant."` |
| Generic failure | The model didn't return usable JSON | `"Couldn't turn that into a rule — try rephrasing it more concretely."` — show as a normal retry-able error |

---

## AI-narrated digest — nothing to build

Plan §4.12 also covers an "AI-narrated digest" (the existing daily/weekly digest rendering as a natural paragraph instead of a fixed template). This is entirely server-side notification-copy generation — **it changes the text of an existing push/email notification, it isn't a separate endpoint or screen.** Nothing in this doc's scope needs building for it; the digest notification just arrives with nicer copy when enabled.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success (ask, list, show, rule-draft) |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Conversation doesn't exist or isn't yours |
| 422 | Plan/quota gate, profile setup incomplete, or the model failed to produce a usable response — always show the server's message text, it's written for the end user |
