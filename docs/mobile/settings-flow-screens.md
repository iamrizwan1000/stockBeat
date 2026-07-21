# StockBeat Mobile — Settings / More Screens

Tab 4 in the bottom nav (Feed · Rules · Inbox · **More**, Plan §4.10). Pair with `settings-api-reference.md` — **read its "real bug fixed this pass" callout first**, it affects what the sound picker in Screen 2 actually does end-to-end.

This tab is a menu hub, not a single screen — each row below opens its own small flow.

---

## Screen 1 — `MoreScreen` (menu hub)

**On load:** no dedicated fetch — reuse the already-cached `GET /me` response (fetched at launch/login) for plan badge, name/email header, and gating flags. No new network call needed just to render this menu.

**Rows, top to bottom:**

1. **Account header** — name, email, plan badge (from `entitlements.plan`; render `subscription_status === "trial"` as a small "Trial ends in N days" chip using `trial_ends_at`, and `"grace"` as a soft warning chip — see Screen 4).
2. **Notification Preferences** → Screen 2. Every plan.
3. **Team & Roles** → Screen 3. **Gate on `entitlements.limits.team_seats`** — hide or show locked-with-upgrade-prompt for Free/Starter-without-seats, consistent with how `rules-flow-screens.md`/`inbox-flow-screens.md` gate their own Pro+ rows.
4. **Subscription / Billing** → Screen 4. Every plan — Free users see it as "Upgrade," paid users see it as "Manage subscription."
5. **Help & Support** → Screen 5. Every plan, including Free — never gated, never hidden.
6. **Data & Privacy** (export / delete account) → Screen 6. Every plan.
7. **Dark Mode** toggle, **Language** picker — client-only, no request, applies immediately.
8. **Log out** / **Log out of all devices** — already documented in `auth-flow-screens.md`, just cross-reference; reuse that logic here rather than re-implementing it.

---

## Screen 2 — `NotificationPreferencesScreen`

**On load:** `GET /settings/notifications`.

**Fields, each a standard settings row:**
- Push / Email / SMS toggles → optimistic update, `PUT /settings/notifications {push_enabled: ...}` (send only the one field that changed).
- Quiet hours: two time pickers (start/end) + a timezone picker (default to device timezone, not a blank value, when the user sets hours for the first time). Submit both start and end together even though the API accepts them independently — a start with no end does nothing server-side (see API doc), so don't let the UI imply otherwise.
- Sound picker: a simple list of the 4 fixed options (`default`/`cha_ching`/`alert`/`chime`) — **not a free-text field**, the server 422s anything else. Consider a "preview" tap that plays the bundled sound file locally (client-side, no request) so the user knows what they're picking before saving. **This now actually affects notification sound** (the bug-fix in the API doc) — worth a first-run tooltip or changelog note if this ships as an update to an app where the setting previously did nothing.

**Save pattern:** either autosave per-field on change (optimistic, revert + toast on 422) or a single "Save" button batching all changed fields into one `PUT` — either works, the endpoint accepts partial bodies either way.

---

## Screen 3 — `TeamScreen` (Pro+)

**On load:** `GET /team`.

**Member list:** avatar/initials, name, email, role badge. **The owner's row has no edit affordance at all** — tapping it does nothing or shows read-only info, since `PUT /team/{member}` on the owner always 422s.

**Pending invites:** a visually distinct section/style (e.g., "Pending" label, dimmed row) below active members, showing email + role + a relative "expires in N days" from `expires_at`. No action available on a pending row besides waiting it out — there's no cancel/resend button because the API doesn't have those actions (see API doc).

**"+ Invite" button** (hidden for `agent`/`viewer` roles — only `owner`/`manager` can invite, matches the `team.role:owner,manager` middleware) → `InviteMemberSheet`:
- Email field, role picker (`manager`/`agent`/`viewer` only — never offer `owner`), optional store-visibility multi-select (fetch `GET /connections` to list stores by name, default to "All stores" i.e. omit the field).
- On submit → `POST /team/invite`. **Show the server's exact validation message on 422** — "already on your team" vs. "invite already pending" vs. "seat limit reached" are three different situations a generic "failed to invite" would flatten into one unhelpful state. The seat-limit message specifically should link to Screen 4 (upgrade).

**Tap an active (non-owner) member** → `EditMemberSheet`: role picker + store-visibility multi-select, pre-filled from the member's current values. Submit → `PUT /team/{member}`. **There is no "remove member" button** — the only demotion available is switching their role to `viewer`; don't build a delete/remove control since nothing on the backend backs it (see API doc's "real backend gap" note — don't silently omit this from the UI without a reason, but don't fake a client-side-only "removal" either, since they'd still be able to authenticate).

**Empty state (no team seats used beyond the owner):** "Invite your team — managers, agents, and viewers all included in your plan" with the Invite button prominent.

---

## Screen 4 — `SubscriptionScreen`

**On load:** reuse cached `entitlements` from `GET /me`; re-fetch fresh on screen focus (cheap, single call) so a background purchase completion is picked up when the user navigates here.

**Current plan card:** plan name, price (from the RevenueCat SDK's own offering data, not this API — this API doesn't serve subscription prices), renewal/trial date from `trial_ends_at`/`expires_at`-equivalent status. Render `subscription_status`:
- `"trial"` — "Trial ends [date]" + prominent upgrade CTA.
- `"active"` — "Renews [date]" (date isn't directly in `/me`'s entitlements — if you need the exact renewal date client-side, read it from the RevenueCat SDK's `CustomerInfo` object, which has it natively; don't try to derive it from this API).
- `"grace"` — a warning banner: "There's a problem with your payment method" + a deep link to the platform's subscription management (App Store / Play Store), since fixing a failed payment method happens there, not in this app.
- `"expired"` / `null` — treat as Free, show the upgrade paywall as the primary content of this screen.

**Upgrade / change plan:** open the RevenueCat SDK's native paywall/purchase sheet directly — do not build a custom pricing table calling any endpoint on this API, there isn't one for subscription products. After a purchase completes in the SDK, **poll `GET /me` for up to ~10–15s** (a few retries) rather than assuming the immediate next call already reflects the new plan — the webhook that actually updates entitlements is asynchronous server-side.

**Restore purchases:** RevenueCat SDK's own restore call, same polling-after pattern.

**SMS top-up:** a separate section/sheet listing `sms_topup_packs` from `GET /me` (name, credit amount, price) — tapping a pack triggers a RevenueCat purchase for that exact `key` as the product identifier, then the same poll-`/me`-for-balance-update pattern (watch `entitlements.sms_balance`). If `sms_topup_packs` is empty, hide this section rather than showing an empty list.

**Manage subscription (cancel/downgrade):** deep link to the native App Store / Play Store subscription management screen — this app has no in-house cancel flow, per Plan §4.8's IAP strategy.

---

## Screen 5 — `SupportChatScreen`

**On load:** `GET /support/thread`, then subscribe to the private `support-thread.{thread_id}` Reverb channel for the rest of the session on this screen (unsubscribe on screen leave).

**Layout:** standard chat bubbles, `direction: "user"` right-aligned (this is the user's own thread, unlike the Inbox module's customer-facing threads), `direction: "staff"` left-aligned with a support-agent avatar/label.

**Compose bar:** free-text → `POST /support/messages`. Append optimistically; the WebSocket event is for *incoming* staff replies, not needed to reflect your own just-sent message.

**Incoming staff replies:** append live from the `message.sent` WebSocket event while this screen is open; if the socket isn't connected (or the app wasn't on this screen), a re-fetch of `GET /support/thread` on screen focus catches up on anything missed — treat the WebSocket purely as a latency optimization, never the only path to seeing a reply.

**Resolved state:** when `thread.status === "resolved"` (learned either from a `GET /support/thread` re-fetch or by convention after staff sends a closing message — there's no separate "thread resolved" WebSocket event, only new messages, so poll/re-fetch status rather than trying to infer resolution from message content), show a one-time CSAT prompt: 👍 / 👎 → `POST /support/csat {rating: 1|0}`. **Don't show the prompt if a rating was already given this resolution** — track locally, since the API only tells you "already rated" via a 422 you'd rather avoid triggering from the UI in the first place. Sending a new message after resolution reopens the thread and clears the "already prompted" flag for the next resolution.

**Empty state (brand-new thread, first-ever open):** a friendly opener ("Hey! What can we help with?") rather than a blank chat — `GET /support/thread` creates the thread on first call regardless, so there's never a true "no thread" state to design for, only an empty message list.

---

## Screen 6 — `DataPrivacyScreen`

**Export:** a single "Request my data" row → confirmation dialog ("We'll email you a full export — this may take a few minutes") → `POST /account/data-export` → toast/success state using the response's own message text. No progress indicator needed or possible — there's nothing to poll.

**Delete account:** a destructive row, separated visually from everything else on this screen (danger zone styling). Tapping it opens a **hard confirmation flow** — at minimum a "type DELETE to confirm" or platform-native destructive-action sheet, given this is irreversible from the client's perspective (see API doc). On confirm → `POST /account/delete-request` → **immediately tear down the session client-side** (clear stored token, navigate to the logged-out/auth stack) without waiting for or attempting any further authenticated call — the token used for this very request is already revoked server-side by the time the response comes back.

**If the caller is a team owner**, consider a stronger warning in the confirmation copy ("this deletes your entire team, including all connected stores and other members' access") since deletion cascades to the whole team, not just this one account — the API doesn't return who's on the team at this point, so pull that context from the already-cached `GET /team` response if available, or just always show the stronger copy for an owner (know this from `team.role` in `GET /me`).

---

## Edge case: a `viewer`/`agent` role opening this tab

Every screen here should still render for read-restricted roles — `GET /settings/notifications`, `GET /team`, `GET /support/thread` all just require auth, not `owner`/`manager`. Only the *write* actions (invite, edit member, send support message is fine for everyone — only team management writes are role-gated) should hide their buttons for non-owner/manager roles, consistent with how `team.role:owner,manager` is enforced server-side throughout this API.

## Edge case: purchase completes but the app is killed before `/me` refreshes

Since entitlement updates land via an async webhook, not the purchase call itself, don't rely on any single moment (purchase-complete callback, next app open) to be *the* place a plan upgrade becomes visible — just make sure `GET /me` is re-fetched at every natural app-foreground/tab-focus point (already true for the Feed tab's entitlement-gated rows elsewhere in this app) so a slightly-delayed webhook still resolves correctly within a normal usage session, without needing special-case recovery logic.
