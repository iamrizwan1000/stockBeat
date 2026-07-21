# StockBeat Mobile — AI Assistant Screens

Cross-cutting feature, not a bottom-nav tab (Plan §4.12). Pair with `ai-api-reference.md` — **read its "two modes" and "messages includes internal turns" sections first**, they drive every screen below.

---

## Entry points (where "Ask AI" appears)

Not one screen — several launch points feeding the same underlying chat surface:

1. **Persistent "Ask AI" button/icon** — always visible somewhere in the shell (e.g. a floating button, or a header icon on the Feed tab). Opens `AskAiScreen` in `data` mode with an empty question.
2. **Suggested-questions chip row** on the Feed/Business Overview header — a small, static, client-side-defined set of chips ("How did I do today?", "What's low on stock?", "What's my profit this week?") built from the real capability list in `ai-api-reference.md`'s "what the Data Copilot can actually answer" table. Tapping a chip opens `AskAiScreen` in `data` mode with that question pre-filled and auto-submitted.
3. **"Ask AI about this" on order detail** (`orders-feed-screens.md`) — opens `AskAiScreen` in `data` mode with a pre-filled, order-specific question (e.g. "What's going on with order #1042?" — client-composed text, there's no order-scoped assistant endpoint, it's just a well-formed question referencing the order number).
4. **"Ask AI about this" on a notification** — same pattern, pre-filled with context about that notification.
5. **A "?"/Help icon in Settings/More** (`settings-flow-screens.md`) — opens `AskAiScreen` in `help` mode. **Always available, never gated, never shows a paywall** — this is the one entry point that's safe to expose to every user on every plan without checking entitlements first.
6. **The Rules create screen's "describe it instead" input** (`rules-flow-screens.md`) — a distinct flow, `RuleDraftReviewScreen` below, not the same chat surface as the other five.

**Gate entry points 1–4 on `entitlements.limits.ai_enabled`** (`GET /me`) — Free teams either don't see these buttons, or see them locked with an upgrade prompt. Entry point 5 (Help) is never gated. Entry point 6 is gated separately on `ai_rule_builder_enabled` (Pro+), per `rules-flow-screens.md`.

---

## Screen 1 — `AskAiScreen` (chat surface)

**Params:** initial `mode` (`"data"` or `"help"`, set by which entry point opened it), optional pre-filled `question`, optional `conversation_id` (resuming from `ConversationHistoryScreen`, below).

**On open with no `conversation_id`:** empty chat, just the composer — don't call any endpoint until the user actually submits a question (or, for a suggested-question chip, submit immediately since the question is already chosen).

**Sending a question:** `POST /assistant/ask {question, mode, conversation_id}`. Show a typing/thinking indicator while waiting — a real answer can involve several tool round-trips server-side, so this isn't always fast; don't assume sub-second.

**Rendering the response — apply the filter from the API doc:**
- Append the user's own message as a right-aligned bubble immediately (optimistic, no need to wait for the response to show it).
- From the returned `conversation.messages`, only render `role: "user"` and `role: "assistant"`-with-non-null-`content"` entries as bubbles. Skip `role: "tool"` entries and any `role: "assistant"` entry with `content: null` entirely — these are the model's internal tool-calling trace, never meant for display.
- On a fresh ask (no prior `conversation_id`), this means re-rendering the full filtered list is safe and simple; on a continuing conversation, only the newly-appended messages need appending (compare against what you already have client-side by `id`).

**Follow-up questions:** keep sending the same `conversation_id` returned from the first call — the composer stays open at the bottom, same screen, no navigation. **First-time `conversation_id` is `null`/omitted**; capture the `conversation.id` from the first response and reuse it for every subsequent question in this session.

**Mode is fixed for the lifetime of one `AskAiScreen` session** — don't add a mode switcher mid-conversation; a `help`-mode conversation and a `data`-mode conversation are conceptually different tools even though they share the same UI. If the user wants the other mode, that's a fresh conversation (fresh entry point).

**Errors:**
- Quota/plan-gate 422s (`ai-api-reference.md`) → show the server's message inline as a system-style message in the thread (not a toast that disappears), with an upgrade CTA if the message implies one. Don't let the failed question silently vanish from the composer — the user typed something real, restore it into the input so they don't have to retype after upgrading. **For the specific "used all N questions this month" quota message**, offer two CTAs, not just upgrade — "Buy more questions" (deep link to `settings-flow-screens.md`'s `SubscriptionScreen` AI top-up sheet) alongside "Upgrade plan," since a merchant on a plan they're happy with may just want more quota this month rather than a full tier change.
- The "couldn't produce an answer" failure → show as a retry-able error bubble ("Something went wrong — try rephrasing your question").

**Empty state (before first question):** for `data` mode, this is a good place to show the suggested-questions chip row again inline if the user arrived here via the persistent button rather than a chip (i.e., unify the chip row across both the Feed header and this screen's empty state, same static list). For `help` mode, a couple of common example prompts ("How do I connect a store?", "Why isn't my order syncing?") serve the same purpose.

---

## Screen 2 — `ConversationHistoryScreen`

**On load:** `GET /assistant/conversations`.

**List:** `title` + relative `updated_at` per row — no message preview needed, `messages` is empty on this list response by design (see API doc). **There's no way to distinguish a `data`-mode conversation from a `help`-mode one in this list** (mode isn't stored/returned per-conversation) — don't try to badge/filter by mode here, just show them chronologically.

**Tap a row** → `AskAiScreen` with that `conversation_id`, fetching full history first via `GET /assistant/conversations/{id}` and rendering it with the same message-filtering rule as Screen 1, then leaving the composer open for follow-ups.

**No rename, no delete** — this API doesn't support either; don't build swipe actions that have nothing to call.

**Empty state:** "Ask your first question — try one of the suggestions on the Feed" (points back at the chip row rather than duplicating example copy).

---

## Screen 3 — `RuleDraftReviewScreen` (from the Rules module's AI entry point)

Already introduced in `rules-flow-screens.md` as an alternative input method on the create-rule screen — full detail here since the endpoint lives in this doc.

**Entry:** a "Describe it instead" toggle/button on the ordinary create-rule form, opening a single text input ("e.g. notify me by text whenever an eBay order is over $200").

**On submit:** `POST /assistant/rule-draft {prompt}`. Show a loading state — this is a real model call, not instant.

**On success:** **don't navigate to a new screen** — pop back to (or reveal) the same create-rule form, now pre-filled from `draft` (`name`, `trigger`, `conditions`, `actions`, `controls`, `sound` if present). The merchant reviews/edits normal form fields from here exactly as if they'd filled them in by hand; submitting still goes through the ordinary `POST /rules`.

**If `valid: false`:** don't block the pre-fill — populate the form anyway (it's still useful as a starting point) and surface each entry in `errors` as an inline field error on the corresponding form control, using the exact same error-rendering code path the manual form already has for its own `POST /rules` 422s (the shapes are identical, since both are validated by the same rules).

**If `trigger_is_advanced` is `true`:** show a small "This trigger needs Premium" hint near the trigger field, even if `valid` happened to be `true` — informational, not a hard block; the real gate is enforced again when the merchant actually saves.

**On the generic "couldn't turn that into a rule" failure:** stay on the prompt input, show the error, let them try rephrasing — don't clear what they typed.

---

## Edge case: a `help`-mode question about business data

If a merchant asks a business-data question through the Help entry point (e.g. "what's my revenue today" typed into the Help chat), the server doesn't reject it — the model itself answers by explaining that this needs the Data Copilot, and mentions upgrading if the team's plan doesn't include it (`ai-api-reference.md`'s `HELP_SYSTEM_PROMPT` behavior). **Render this as a completely normal assistant message**, not an error — there's nothing for the client to detect or special-case here, the model's own prose handles it.

## Edge case: switching teams mid-conversation

Not currently possible in this app (no team-switcher, per `settings-api-reference.md`'s note on invites) — a conversation is always scoped to the user's one current team, so there's no client-side handling needed for this case.

## Edge case: a very long conversation

There's no pagination on `GET /assistant/conversations/{id}` — the full message history (including all the filtered-out tool-call turns) comes back in one call every time. For a conversation that's grown very long, this means an increasingly large payload on every open — not a problem at realistic usage levels, but don't build assumptions of infinite scroll/pagination into this screen; if it ever becomes an issue, that's a backend change, not something to work around client-side.
