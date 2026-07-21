# StockBeat Mobile — Inbox Screens

Tab 3 in the bottom nav (Feed · Rules · **Inbox** · More, Plan §4.10). Pro plan and higher only. Pair with `inbox-api-reference.md` — **read its "important thing to understand" section first**, since this module's biggest UX implication (no "new conversation" button) comes from there.

---

## Screen 1 — `InboxScreen` (thread list)

**Gating:** check `entitlements.limits.inbox_enabled` from `GET /me` before showing this tab at all — on Free/Starter, either hide the tab entirely or show it locked with an upgrade prompt in its place, consistent with how other Pro+ features are handled elsewhere in this app.

**On load:** `GET /threads`.

**Each row:**
- Customer name (fall back to email, then to "Unknown customer" if somehow both are null — shouldn't happen but don't crash on it).
- Platform icon (`channel`).
- Order number if `order_id` is present ("Re: #1042") — omit this line entirely for a pre-sale thread with no order.
- Last message preview + relative timestamp (`last_message_at`).
- Assignment avatar/initials if `assigned_to` is set.

**Filters:** "Assigned to me" toggle → `?assigned_to={my_user_id}`. Optionally an "Unassigned" filter — note there's no server-side `assigned_to=null` filter documented; if you want that, fetch the full list and filter client-side (thread volumes here are realistically small).

**No "+ New conversation" button** — see the API reference. If a merchant wants to start a conversation, the natural path is via an order (Screen in `orders-feed-screens.md`'s order detail "Message customer" button), not this screen.

**Empty state:** "No conversations yet — messages from eBay buyers and order-linked replies will show up here."

**On tap of a row:** `ThreadDetailScreen` (Screen 2).

---

## Screen 2 — `ThreadDetailScreen`

**Params:** `thread_id`.

**On load:** `GET /threads/{id}/messages`. Also worth fetching `GET /connections` (likely already cached from the Connections module) to resolve this thread's `connection_id` → `capabilities.messaging_mode`, since that determines what you show below.

**Header:** customer name, platform icon, order number (tappable → order detail, if present), assign-to-member control (a small avatar/menu → `POST /threads/{id}/assign`).

**Message list:** standard chat bubble layout — `direction: "in"` left-aligned, `direction: "out"` right-aligned. For outbound messages, show a small status indicator based on `status`:
- `queued` → a subtle spinner/clock icon
- `sent`/`delivered` → a checkmark (single vs. double if you want to distinguish, though the distinction isn't guaranteed to be meaningful across every channel)
- `failed` → a red warning icon, **tappable to reveal `failure_reason`** — don't just show a generic "failed to send," the server gives you a real, specific reason

**Compose bar:**
- Free-text input → `POST /threads/{id}/messages {body}`.
- A template-picker button (icon in the compose bar, opens a sheet listing `GET /reply-templates`) → selecting one sends immediately via `POST /threads/{id}/messages {reply_template_id}`, or (better UX) inserts the *rendered* preview into the text field first so the merchant can edit before sending — but since rendering happens server-side, you'd need to either maintain your own client-side copy of the substitution logic (duplicating server behavior, prone to drift) or just send-on-select and accept that templates are a "send as-is" action, not an editable draft. **Recommend the latter** — simpler, and matches how templates are meant to be used (pre-approved wording).

**After sending:** append the returned `message` optimistically, but **watch its `status`** — if it comes back `failed` on the very first render (not just eventually), show the failure inline immediately rather than a generic "sent" checkmark that then has to flip to an error state.

**If `capabilities.messaging_mode` for this thread's connection is `"approval_gated"` (Etsy) and messages are failing:** consider showing a persistent banner at the top of the thread ("Etsy messaging needs additional approval for this shop") rather than making the merchant discover it by trying to send and getting a failure each time.

---

## Screen 3 — `ReplyTemplatesScreen` (Settings or a menu off the Inbox tab)

**Purpose:** CRUD for the team's saved reply templates.

**List:** `GET /reply-templates` — name + body preview.

**Create/edit form:** name (text), body (multiline text with a hint showing the three real variables — `{customer_name}`, `{order_number}`, `{tracking}` — perhaps as tappable chips that insert the literal token at the cursor). **Warn if the body contains `{order_number}`/`{tracking}` variables**, per the API reference's note that these render as empty strings on a pre-sale/orderless thread — not a hard block, just a small hint ("this template assumes an order — it'll show blanks on general inquiries").

**Delete:** confirm dialog → `DELETE /reply-templates/{id}`.

---

## Edge case: a thread's connection was disconnected

Same situation as `orders-feed-screens.md`'s equivalent edge case — a thread can outlive its connection. Sending will fail with whatever the channel adapter reports; handle it the same way (show `failure_reason`, don't crash on a missing capability lookup).

## Edge case: `assigned_to` pointing at a removed team member

Same caution as the Rules module's `notify_member` action — resolve the assignee's name from a live `GET /team` call, don't cache it indefinitely, and handle a not-found id gracefully ("Unassigned" or "a removed team member" rather than a blank/crash).
