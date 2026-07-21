# StockBeat Mobile — Notification Center & Announcements Screens

Pair with `notifications-api-reference.md` — **read the "`thread_id` means two different things" and "SMS never appears here" callouts first**, they change how you build the tap-to-navigate logic below.

---

## Screen 1 — `NotificationCenterScreen` (bell icon)

Reachable from a bell icon in the app header, visible from any tab — not a bottom-nav destination itself.

**On open:** `GET /notifications`.

**Each row:** `title` + `body`, relative `created_at`, unread visual state (bold/dot) when `read_at` is `null`. Group by day if the list is long enough to benefit — no server-side grouping, purely a client-side date bucket.

**Badge count:** there's no unread-count endpoint — compute it client-side from the last `GET /notifications` response (count of `read_at === null`) and refresh on a reasonable cadence (app foreground, after visiting this screen, after a new push arrives if you're wiring push-received events to a local refresh).

**On tap of a row:**
1. Immediately call `POST /notifications/read {ids: [thatRow.id]}` (optimistic — update the row's read state locally right away, don't block navigation on the response).
2. Navigate based on `type` per the table in `notifications-api-reference.md` — **branch on `type` before looking at `data`**, since `data.thread_id` means a different screen depending on whether `type` is `support_reply` or `inbox_message`.
3. For `type`s with no sensible destination (`rule_email`, `admin_broadcast`, `digest`-with-empty-data edge cases), just mark read and stay on this screen — don't force a navigation that has nowhere real to go.

**"Mark all as read" action** (e.g. a header button): `POST /notifications/read` with `ids` omitted entirely — update every row's local unread state to read after a successful response.

**Empty state:** "You're all caught up" — this is a normal, common state, not an error.

**Don't build:** an SMS-specific section or filter — SMS-delivered rule actions never produce a row here (see the API doc's callout). If a merchant expects to see their text alerts listed, that's an app-education moment (a tooltip/FAQ entry), not something to fake with client-side data that doesn't exist.

---

## Screen 2 — Announcement banner (not a separate screen — a strip on the Feed tab)

**On Feed tab load/foreground:** `GET /announcements`, filter out any `id`s already in local "dismissed" storage, render the remainder as a dismissible strip/card above the order list (or wherever the app's existing "what's new" pattern lives).

**Dismiss (`dismissible: true` only):** an X/close tap — **store the `id` locally, there is no server call**. Don't build a loading state or error handling around "dismissing" since nothing is sent over the network.

**Multiple active announcements:** show them as a small carousel/stack rather than all at once if there's ever more than one — the API doesn't cap how many can be simultaneously active for a given audience.

**Non-dismissible announcements** (`dismissible: false`): persistent until the admin-set `ends_at` passes and the announcement stops being returned — don't add a close control for these.

---

## Edge case: a `support_reply` or `inbox_message` notification whose thread no longer resolves

If the underlying support/inbox thread was somehow removed or is no longer accessible (shouldn't normally happen, but don't crash on it) by the time the notification is tapped, handle the resulting 404 from `GET /threads/{id}/messages` or `GET /support/thread` the same way those screens already handle a not-found thread — fall back to the relevant tab's list screen rather than a blank/crashed detail view.

## Edge case: notification arrives while `NotificationCenterScreen` is open

There's no WebSocket/live-update channel for the Notification Center itself (unlike the Support chat's Reverb channel, `settings-api-reference.md`) — a new item won't appear until the next `GET /notifications` call. Re-fetch on screen focus is sufficient; don't build a polling loop while this screen is open, that's more complexity than the feature needs.
