# StockBeat Mobile — Settings / More API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Tab 4 in the bottom nav — "More" (Plan §4.7/§4.8/§4.9/§4.10). Unlike Feed/Rules/Inbox, this isn't one screen backed by one resource — it's a menu hub fanning out to several small, mostly-independent areas. This doc covers all of them:

1. [Notification preferences](#notification-preferences) — every plan
2. [Team & roles](#team--roles-pro) — Pro+ only
3. [Data export & account deletion](#data-export--account-deletion) — every plan
4. [Billing & subscription](#billing--subscription-native-iap) — every plan (what's visible differs by plan)
5. [Help / support chat](#help--support-chat) — every plan, including Free
6. [Dark mode & language](#dark-mode--language) — client-only, no API

## ⚠️ Real bug fixed this pass

`NotificationPreference.sound` (the "sound selection" setting this doc's first section covers) was being saved by `PUT /settings/notifications` but **never read by anything that actually sends a push** — only a rule's own per-rule `sound` (Plan §4.4) reached the FCM payload, and most rules don't set one, so the FCM payload was left untouched and the phone just played its OS default regardless of what the user picked here. Fixed: `SendPushNotificationAction` now falls back to the recipient's saved `NotificationPreference.sound` whenever the caller (a rule, an admin broadcast, anything) doesn't pass its own explicit sound. A rule's own sound still wins when it's set — this is a fallback, not an override. Also tightened `PUT /settings/notifications`'s `sound` validation from "any string ≤50 chars" to the same fixed bundled-sound-file catalog `Rule::sounds()` already enforces for rules (`default`/`cha_ching`/`alert`/`chime`) — previously a client could save `"airhorn"` and it would 200 successfully while silently never producing any actual sound file lookup that resolves.

---

## Notification preferences

Personal, per-user (not per-team) delivery gate — this is separate from a *rule's* own `controls.quiet_hours`/per-channel mute (`rules-api-reference.md`), which decides whether a rule fires at all. This is checked *after* a rule already fired: "would deliver, but this person has push off / is in their own quiet hours right now." It gates push and (for marketing/broadcast mail) email; it never hides anything from the in-app notification center itself — a muted push is still logged there, just not sent to the device.

### `GET /settings/notifications`

**Requires auth.** Returns sensible defaults (`firstOrNew`, not 404) for a user who's never saved preferences — no separate "not configured yet" state to handle.

```json
{ "success": true, "message": null, "data": { "preferences": {
  "push_enabled": true,
  "email_enabled": true,
  "sms_enabled": true,
  "quiet_hours_start": null,
  "quiet_hours_end": null,
  "quiet_hours_timezone": null,
  "sound": "default"
} } }
```

### `PUT /settings/notifications`

**Requires auth.** All fields optional — send only what changed, this is a partial update (`sometimes` on every rule).

| Field | Type | Notes |
|---|---|---|
| `push_enabled` / `email_enabled` / `sms_enabled` | boolean | |
| `quiet_hours_start` / `quiet_hours_end` | string or `null` | `"HH:MM"` 24-hour, e.g. `"22:00"`. Setting one without the other is accepted but pointless — `isWithinQuietHours()` requires both to be non-empty to do anything. Wrapping ranges work (`22:00`→`08:00` correctly spans midnight). |
| `quiet_hours_timezone` | string or `null` | Any valid IANA timezone (e.g. `"Australia/Sydney"`). Falls back to the user's profile timezone, then UTC, if left `null`. |
| `sound` | string | **Must be one of:** `"default"`, `"cha_ching"`, `"alert"`, `"chime"` — the same fixed catalog `rules-api-reference.md` documents for a rule's own custom sound, since both ultimately resolve to the same bundled sound files on-device. Anything else 422s. |

**Success — 200:** same `preferences` shape as the GET.

**Building the picker UI:** since this list is a small fixed set shared with the Rules module's sound picker (`rules-api-reference.md`), consider a single shared component rather than two separate hardcoded lists that could drift apart — there's no `GET /sounds` endpoint, this catalog is baked into both docs from the same server-side source (`Rule::sounds()`).

---

## Team & roles (Pro+)

**Gate this whole section on `entitlements.limits.team_seats` from `GET /me`** — a `null`/`1` value means solo, hide or lock the Team row in the More menu; Starter and above get real multi-seat teams.

Roles: `owner` (immutable — see below), `manager` (everything an owner can do except be removed/demoted), `agent` (view + inbox only — matches `team.role:owner,manager` middleware gating every write endpoint elsewhere in this API), `viewer` (read-only).

### `GET /team`

**Requires auth.** Returns both active members and outstanding pending invites in one call — a real UI should render invites as a distinct "Pending" row style (e.g. dimmed, with a "Resend"-style affordance if you want one — **there's no resend endpoint**, only re-inviting after the old one expires, see below).

```json
{ "success": true, "message": null, "data": {
  "members": [
    { "id": 1, "role": "owner", "store_visibility": null, "user": { "id": 1, "name": "Jamie Rivera", "email": "jamie@example.com" } }
  ],
  "pending_invites": [
    { "id": 1, "email": "sam@example.com", "role": "manager", "status": "pending", "expires_at": "2026-07-23T00:00:00+00:00" }
  ]
} }
```

`store_visibility`: `null` means "sees every connected store" (the common case); an array of connection ids restricts that member to only those stores' orders/inbox/analytics. Cross-reference against `GET /connections` (`connections-api-reference.md`) to render store names instead of raw ids.

**422** (`"Complete profile setup first."`) if somehow called before onboarding finished — shouldn't be reachable in normal navigation since `needs_profile_setup` from `GET /me` gates entry into the main app shell.

### `POST /team/invite`

**Requires auth**, `owner`/`manager` role.
```json
{ "email": "sam@example.com", "role": "manager", "store_visibility": [1, 2] }
```
`email` required. `role` required, one of `manager`/`agent`/`viewer` — **`owner` is not an invitable role**, there is exactly one owner per team and it's whoever created it. `store_visibility` optional, omit/`null` for full access.

**Success — 201:** `{invite: {...}}`, same shape as the list. The invite is emailed with a 7-day expiry.

**Real validation failures worth handling distinctly in the UI** (both 422 on the `email` field, but different messages — show the server's message verbatim rather than a generic "failed to invite"):
- `"This person is already on your team."`
- `"An invite is already pending for this email."` — to actually "resend," the current invite must expire first (7 days) or you re-invite the same email once it does; there's no cancel/resend action.
- `"You've reached your plan's team seat limit ({N}). Upgrade to invite more members."` — pending invites count against the seat limit too, not just active members, so this can fire even with fewer active members than the limit if invites are outstanding.

### `PUT /team/{member}`

**Requires auth**, `owner`/`manager` role. Body: `{role?, store_visibility?}`, both optional (partial update).

**Success — 200:** `{member: {...}}`.

**The owner's own row can never be updated this way** — `422` with `{"role": ["The team owner's role can't be changed."]}` if attempted. There is no ownership-transfer feature. **Don't render an edit control on the owner's own row at all** — attempting it is a guaranteed error, not a real action.

**There is no member-removal endpoint.** A mobile "remove from team" action doesn't exist server-side — the closest available action is demoting to `viewer`. Suspending a member entirely (`suspended_at`, which makes every mobile API call 403 for them — see `TeamMemberSuspensionTest`) is admin-panel-only, not exposed to the team owner via this API. If the product wants seller-initiated removal, that's a real backend gap, not a mobile implementation gap — flag it rather than trying to fake it client-side.

### How someone actually joins a team

There's no "accept invite" screen or endpoint. A pending invite is auto-redeemed the moment the invited email address completes `POST /profile/setup` (`auth-flow-screens.md`) for the first time — matched purely by email, transparently, with no separate step. Only applies to a brand-new user (no existing team membership of their own); someone who already belongs to a different team keeps it and their invite just sits pending until it's redeemed or expires. Nothing for the mobile client to build here beyond the normal signup flow already documented in `auth-flow-screens.md`.

---

## Data export & account deletion

Both are real, working GDPR actions (not stubs) — this is the seller-initiated pair; a separate admin-initiated path exists in the admin panel but is out of scope for mobile.

### `POST /account/data-export`

**Requires auth.** No body.
```json
{ "success": true, "message": "We're preparing your data export — you'll receive it by email shortly.", "data": null }
```
Compiles a real JSON export (profile, team, team members, store connections — credentials excluded, orders with items/notes, rules) and emails it as an attachment. **There is no in-app download or status-polling** — the message itself is the entire UI contract; show it as a toast/confirmation and be done. No "export ready" push notification either.

### `POST /account/delete-request`

**Requires auth.** No body.
```json
{ "success": true, "message": "Your account has been scheduled for deletion.", "data": null }
```

**This is immediate and irreversible from the client's perspective — build a hard confirmation step before calling it** (e.g. type-to-confirm or a destructive-action sheet), not a single tap. Real server behavior:
- Every Sanctum token for the user — **including the one that just made this request** — is revoked immediately, server-side, as part of the same request.
- If the caller is a team owner, the entire team (and everything hanging off it — connections, orders, rules) is soft-deleted with them, not just their own account.
- A 30-day grace period follows server-side before hard deletion; **there is no restore endpoint**, so don't build a "cancel deletion" UI — it doesn't exist.

**Client handling:** after a 200 here, don't attempt any further authenticated call with the current token — it's already dead. Go straight to the logged-out state (same teardown as `POST /auth/logout`, `auth-api-reference.md`) without calling logout separately.

---

## Billing & subscription (native IAP)

**All purchases happen through the RevenueCat SDK directly on-device — there is no `POST /purchase` or `POST /subscribe` endpoint in this API.** The backend only ever *observes* purchases after the fact via a server-to-server RevenueCat webhook (`hooks/revenuecat`, outside `/api/v1`, not callable by the mobile app — it's authenticated with a fixed shared secret RevenueCat sends, not a user token). The mobile-facing contract is:

1. Configure the RevenueCat SDK with `appUserID` = this user's numeric `id` (from `GET /me`'s `user.id`) — this is exactly how the backend links a purchase back to a `Team` (`rc_app_user_id`/`app_user_id` matching, Plan §6.1).
2. Drive the actual purchase UI (paywall, price display, restore purchases) entirely through the RevenueCat SDK's own offerings/packages API — this backend does not serve product prices or descriptions for subscription tiers (only for SMS top-ups, see below).
3. After a purchase completes, **the entitlement change isn't instant on this API** — it lands whenever RevenueCat's webhook reaches the backend (real-world: seconds, not guaranteed). Re-fetch `GET /me` after a purchase (poll a few times over ~10–15s if needed, same pattern as the OAuth-connection polling workaround in `connections-api-reference.md`) rather than assuming the very next `/me` call already reflects the new plan.

### The product ID contract (must match exactly on both sides)

RevenueCat product/offering identifiers are a **fixed whitelist in code**, not admin-editable — configuring a product in App Store Connect / Play Console under a different identifier than these means it's silently ignored by the webhook (`ProcessRevenueCatEventAction`'s `default => null` — an unrecognized `product_id` grants nothing, no error, no log the mobile app can see):

| Product ID | Grants |
|---|---|
| `starter_monthly` | Starter |
| `pro_monthly` / `pro_yearly` | Pro |
| `premium_monthly` / `premium_yearly` | Premium |

There is no Free product — Free is simply the absence of an active subscription.

### `GET /me`'s billing-relevant fields (full shape in `auth-api-reference.md`)

```json
"entitlements": { "plan": "pro", "limits": { "...": "..." }, "subscription_status": "active", "trial_ends_at": null, "sms_balance": 42 }
```
`subscription_status`: `"trial"` | `"active"` | `"grace"` | `"expired"` | `null` (no subscription row yet — a brand-new Free account). `"grace"` means a renewal payment failed but access hasn't been cut yet — worth a soft in-app banner ("update your payment method") rather than treating it like `"expired"`. `trial_ends_at` is only non-null during an active 7-day trial (Plan §6.3).

### SMS top-up packs (consumable IAP)

Unlike subscriptions, top-up pack pricing/catalog **is** served by this API (admin-editable, Plan §8.7.3) — but the purchase itself is still 100% through RevenueCat, same as above. `key` here is the exact RevenueCat product identifier to pass to the SDK's purchase call.

`GET /me`'s `sms_topup_packs`:
```json
[ { "key": "sms_100", "name": "100 SMS", "sms_credits": 100, "price_usd": "2.99" } ]
```
Empty array is a real, valid state (no active packs configured) — hide the top-up section entirely rather than showing a blank list.

**There is no SMS usage history/ledger endpoint** — `entitlements.sms_balance` (current balance only) is all that's available. Don't build a "usage history" screen against this API; it isn't there.

---

## Help / support chat

Every plan, including Free (Plan §4.9) — always show this row in the More menu, no gating.

**One thread per user, forever** — not per-conversation. `GET /support/thread` gets-or-creates it; there's no "start a new support conversation" concept, just one continuous history that gets reopened by either side sending a message after it was marked resolved.

### `GET /support/thread`

**Requires auth.**
```json
{ "success": true, "message": null, "data": {
  "thread": { "id": 1, "status": "open" },
  "messages": [
    { "id": 1, "direction": "user", "body": "My orders stopped syncing", "attachments": null, "created_at": "2026-07-16T02:00:00.000000Z" }
  ]
} }
```
`status`: `"open"` | `"awaiting_user"` | `"resolved"`. `direction`: `"user"` (this person) or `"staff"` (support agent) — internal staff notes (`direction: "note"`) are filtered out server-side, never sent to this endpoint. Full history returned in one call, oldest first — same no-pagination approach as `inbox-api-reference.md`'s thread messages.

### `POST /support/messages`

**Requires auth.** `{body}` (required, max 4000 chars). **Success — 201:** `{message: {...}}`, same shape, `direction: "user"`. Sending a message on a `resolved` thread silently reopens it to `open` — no special handling needed client-side, just send.

### `POST /support/csat`

**Requires auth.** `{rating: 1}` (1 = 👍, 0 = 👎 — integer, not boolean). **Only works on a resolved thread, and only once per resolution:**
- `422` `"This thread is not resolved yet."` if the thread is still `open`/`awaiting_user` — **don't show the rating prompt at all unless `status === "resolved"`.**
- `422` `"You already rated this conversation."` if called twice for the same resolution — track locally that a rating was already given this "episode" (a fresh message after resolution starts a new unrated episode, since a later resolution can be rated again).

**Success — 200:** `{thread: {id, csat}}`.

### Real-time delivery (Reverb WebSocket)

A staff reply arrives over a private WebSocket channel while the app is foregrounded, in addition to (not instead of) push+email for when it isn't:

- **Channel:** `support-thread.{thread_id}` (private channel — requires broadcasting auth).
- **Event name:** `message.sent`. **Payload:** `{id, thread_id, direction, body, created_at}` — same shape as the REST message resource minus `attachments`.
- **Broadcasting auth endpoint:** `POST /broadcasting/auth` — **note this is NOT under the `/api/v1` prefix**, it's Laravel's default broadcasting auth route, but it accepts the same Sanctum bearer token as every other authenticated endpoint in this API.
- Connection details (Reverb host/port/app key) are deployment-specific — get the real values from the backend team for each environment rather than hardcoding, they aren't served by any endpoint in this API.
- **Degrade gracefully:** the broadcast event is queued (not synchronous), so a Reverb hiccup just means the reply shows up whenever the app is next opened / thread re-fetched (`GET /support/thread`) instead of live — don't build any retry/error UI around the socket connection itself, just fall back to polling-on-foreground if the socket isn't connected.
- A reply that misses the WebSocket (app backgrounded/killed) always also arrives as a push notification and a full-content email (the user can reply directly from either, per Plan §4.9) — the WebSocket is a latency optimization, not the only delivery path.

---

## Dark mode & language

Client-only preferences (Plan §4.10) — no backend involvement, no endpoint, store locally (device settings / secure local storage). Don't build any sync-to-server logic for these; there is nothing on this API to sync to.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 201 | Invite / support message created |
| 401 | Missing/invalid/revoked bearer token — **immediately true of the token used to call `account/delete-request` itself, right after that call succeeds** |
| 403 | Caller's team membership is suspended (admin-only action, not reachable from this API) |
| 404 | Team member doesn't belong to your team, or no support thread exists yet (CSAT with no thread ever created) |
| 422 | Validation failure — team seat limit, owner role-change attempt, duplicate invite, unrated-until-resolved CSAT, bad `sound` value, etc. Always surface the server's message text; it's written to be shown to the user, not just logged. |
