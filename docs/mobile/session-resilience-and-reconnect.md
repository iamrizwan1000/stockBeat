# StockBeat Mobile — Session Resilience & Reconnect Guide

No new endpoints in this doc — everything referenced below (`GET /me`, `GET /billing/entitlements`, `POST /billing/sync`) already exists and already returns correct data (see `billing-topup-guide.md`, `settings-api-reference.md`). This is a **client-behavior spec**: what the app needs to build so a connectivity gap — device offline at launch, offline mid-session, or backgrounded through a billing event — resolves correctly the moment the network comes back, instead of silently showing stale or wrong state.

Added 2026-07-31, closing a real gap found in an audit: today none of this exists client-side. `network-resilience-and-edge-cases.md` already documents the server's side of "what happens on a flaky connection" (double-submit guards, no offline queueing); this doc is the client-side counterpart specifically for **session and subscription/entitlement state**, which has its own failure modes beyond a single request retry.

---

## 1. The failure modes this closes

| # | Scenario | Current behavior (broken) | Required behavior |
|---|---|---|---|
| 1 | App launches with no connectivity, but a valid token is in Keychain | `refreshMe()` fails, app treats this the same as "not logged in" and shows the logged-out `WelcomeScreen` — user has to OTP back in even though nothing was wrong with their session | Recognize this as an offline failure, not an auth failure. Keep the token, keep the user in the authenticated shell, show an offline state, and auto-retry |
| 2 | Device goes offline mid-session, then reconnects | Nothing observes the transition. Entitlements only refresh if the user happens to land on the Subscription screen or Notification Center | A connectivity listener triggers an entitlement refresh the instant the device comes back online |
| 3 | App is backgrounded while a billing webhook lands (renewal, `BILLING_ISSUE`, `EXPIRATION`), then foregrounded | Stale entitlements shown until next screen-focus | Foreground resync, independent of which screen is active |
| 4 | Cold launch, brief connectivity gap | Blank/spinner screen until the network call resolves | Render last-known entitlements from cache immediately, reconcile in the background |

None of this requires the server to behave differently — `POST /billing/sync`'s "pull-and-overwrite, safe to call anytime" design (`network-resilience-and-edge-cases.md`, line 42) and `GET /me`'s idempotent read already make repeated/delayed calls safe. The gap is entirely that nothing on the client calls them at the right moments.

---

## 2. Cold launch while offline — don't log the user out

In the auth bootstrap (`AuthContext`'s launch effect), a `refreshMe()` failure currently has one path regardless of cause. Split it:

- **401** → the token is genuinely invalid/revoked. Clear it, route to `WelcomeScreen`. (No change — this is correct today.)
- **Network error / timeout / 5xx** → the token itself was never rejected. Keep it in Keychain, keep the app in a "possibly-authenticated" state, and show a lightweight offline placeholder (not the full logged-out flow, not a spinner that never resolves) — e.g. "Can't reach StockBeat — check your connection," with a manual retry button as a fallback.
- Either way, wire this state into the reconnect listener in §3 so it self-heals the moment connectivity returns — the user should never *need* to tap retry if the network comes back on its own, that's just the fallback for when it doesn't.

---

## 3. Detect reconnect and resync

Add `@react-native-community/netinfo` (not currently a dependency). Subscribe once, near the app root (alongside where `AuthContext` already lives):

- On a transition from offline → online (`state.isConnected` / `isInternetReachable` flipping `false → true`), trigger:
  - If session bootstrap never completed (§2's offline state) → run the full `refreshMe()` bootstrap.
  - If already logged in → a lighter refresh is enough: `getBillingEntitlements()` (or `refreshMe()` if other `/me` fields are also due for a refresh).
- Don't fire on every reconnect blindly if a refresh is already in flight — guard with a simple in-flight flag so a flappy connection (on/off/on within a few seconds) doesn't stack duplicate calls. (`GET /me` is safe to call repeatedly either way, this is about avoiding wasted requests, not correctness.)
- This listener is global/app-lifetime, not screen-scoped — it must keep working regardless of which screen is currently focused.

---

## 4. Foreground resync (AppState)

There is currently exactly one `AppState` listener in the app, and it's scoped to the OAuth-connect-return flow (`OAuthConnectScreen`) — nothing observes app-level foreground/background for billing purposes.

Add an app-root `AppState` listener:
- On `background`/`inactive` → `active` transition, trigger the same lighter entitlements refresh as §3.
- This is deliberately independent of the NetInfo listener in §3 — a phone can stay connected the whole time the app was backgrounded and still have missed a webhook-driven state change (renewal, billing issue, expiration) that arrived while the app wasn't running. Foreground resync and reconnect resync are two different triggers for the same underlying refresh call, not the same mechanism.

---

## 5. Cache last-known entitlements + staleness

Persist the most recent successful `entitlements` payload (from `GET /me` or `GET /billing/entitlements`) to `AsyncStorage`, keyed per user id, alongside a `lastVerifiedAt` timestamp:

```json
{ "entitlements": { "...": "..." }, "lastVerifiedAt": "2026-07-31T10:00:00.000Z" }
```

- **On cold launch**, render from this cache immediately (no blank/spinner state for returning users) while a live `refreshMe()`/`getBillingEntitlements()` call runs in the background and replaces it as soon as it resolves.
- **Staleness indicator**: if `lastVerifiedAt` is older than a threshold (24h is reasonable — a billing state change is never so time-critical that this needs to be tighter, per the notification-based alerting already covering `subscription_payment_issue`/`subscription_expired` in real time) **and** a live refresh hasn't succeeded yet since launch, show a subtle "last verified X ago" hint rather than presenting the cached state as unconditionally current. This is purely a display nuance — it never blocks the UI or forces a re-auth.
- Overwrite the cache on every successful entitlements fetch, including the ones triggered by §3 and §4 — this keeps the "last known good" state fresh without needing a separate cache-invalidation path.

---

## 6. Native push tap handling

`notifications-api-reference.md` (line 64) already documents that `trigger`/`platform` are included directly in the raw FCM data payload — specifically so a native tap handler can route without an extra `GET /notifications` round-trip. That handler doesn't exist yet (today's FCM handlers only `console.log` the message). Add it:

- `messaging().onNotificationOpenedApp(remoteMessage => ...)` — app was backgrounded, user tapped the OS notification.
- `messaging().getInitialNotification()` — app was fully killed, user tapped the OS notification to launch it (check this once at startup, after navigation is ready).
- Both should route through the **same `type`-based table already implemented in `NotificationCenterScreen.handleTap()`** — don't build a second, parallel routing table. In particular this closes the loop for `subscription_payment_issue`/`subscription_expired`/`trial_reminder` → Subscription screen, and `rule_push` → order detail when `order_id` is present, exactly as the in-app list already does.
- Remember the `thread_id` ambiguity called out in `notifications-api-reference.md` — branch on `type` first, never on the presence of a field alone, in this handler too.

---

## 7. Mobile implementation checklist

- [ ] Split cold-launch `refreshMe()` failure handling: 401 → logged out (unchanged), network/5xx → offline state, token preserved.
- [ ] Add `@react-native-community/netinfo`, app-root listener, reconnect triggers a refresh (bootstrap if session never completed, lighter entitlements call otherwise).
- [ ] Add app-root `AppState` listener, foreground triggers the same lighter entitlements refresh.
- [ ] Cache last-known `entitlements` + `lastVerifiedAt` in `AsyncStorage`; render from cache on cold launch; staleness hint past 24h with no successful live refresh yet.
- [ ] Wire `onNotificationOpenedApp` + `getInitialNotification` in `pushNotifications.ts`, routed through the same type-table as `NotificationCenterScreen.handleTap()`.
- [ ] Manual retry affordance on the offline state from §2, as a fallback for when auto-reconnect detection doesn't fire (e.g. captive portal Wi-Fi that reports connected but isn't actually reachable).

---

## See also

- `network-resilience-and-edge-cases.md` — the server-side counterpart: no offline queueing, idempotency guards, per-endpoint double-submit safety.
- `billing-topup-guide.md` — the RevenueCat webhook → `GET /me` reconciliation model, `POST /billing/sync`'s fail-open behavior.
- `notifications-api-reference.md` — the full `type`/`data` routing table this doc's push handler must reuse, and the FCM data-payload fields (`trigger`, `platform`) it depends on.
- `settings-api-reference.md` — field-by-field `/me` and `/billing/entitlements` shapes.
