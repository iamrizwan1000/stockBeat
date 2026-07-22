# StockBeat Mobile — Connections API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md` — read that first if you haven't.

Pair this with `connections-flow-screens.md` for the actual screen-by-screen UX, especially the OAuth-callback edge case, which is the one thing in this module that behaves differently from a typical mobile OAuth flow.

## Platform status — verified against real code and real credentials, 2026-07-22

| Platform | `platform` key | Connect method | Status today |
|---|---|---|---|
| WooCommerce | `woo` | Key intake (immediate) | **Fully real.** Connects synchronously — no browser step. |
| Shopify | `shopify` | OAuth | **Real.** Real Partner app credentials are configured — `start` returns a genuine, working authorization URL. |
| eBay | `ebay` | OAuth | **Real (sandbox).** Real sandbox Developer Portal credentials configured. |
| Etsy | `etsy` | OAuth+PKCE | **Real.** Real Developer app credentials configured. |
| TikTok Shop | `tiktok` | OAuth | **Real.** Real Partner Center app credentials configured. |
| Amazon | `amazon` | OAuth | **Not ready.** No developer credentials exist yet (SP-API vetting takes weeks) — `POST /connections/amazon/start` always 422s with a clean message. Don't build a dead end for this one; either hide it or show "coming soon." |

For all four OAuth platforms, "real" means the authorization URL generation and the token-exchange code are genuine, tested code paths — not that a full merchant-in-the-loop OAuth grant has been exercised end to end with a live browser session. Build and test against these for real; just know the very last mile (an actual merchant approving on Shopify's/eBay's/Etsy's/TikTok's own consent screen) hasn't been manually walked yet.

---

## `POST /connections/{platform}/start`

**Requires auth.** Starts connecting a store. `{platform}` is one of `shopify` `woo` `ebay` `etsy` `amazon` `tiktok`.

**Request body — shape depends on `platform`:**

| Platform | Body |
|---|---|
| `woo` | `{ "name": "My Store", "credentials": { "store_url": "https://shop.example.com", "consumer_key": "ck_...", "consumer_secret": "cs_..." } }` |
| `shopify` | `{ "name": "My Store", "credentials": { "shop_domain": "my-shop.myshopify.com" } }` |
| `ebay` / `etsy` / `tiktok` | `{ "name": "My Store", "credentials": {} }` — no credentials needed at this step, everything happens on the platform's own consent screen. |
| `amazon` | Same shape as ebay/etsy — will 422 regardless, see below. |

| Field | Rules |
|---|---|
| `name` | required, string, max 255 — this is a **display name you choose**, not fetched from the platform. Suggest the business name from profile setup, let them edit it. |
| `credentials.store_url` (woo only) | required, valid URL |
| `credentials.consumer_key` / `consumer_secret` (woo only) | required, string |
| `credentials.shop_domain` (shopify only) | required, must match `^[a-z0-9-]+\.myshopify\.com$` (case-insensitive) |

**Success — 201 (WooCommerce, connects immediately):**
```json
{
  "success": true,
  "message": null,
  "data": {
    "connection": {
      "id": 1,
      "platform": "woo",
      "name": "Rivera Vintage Co",
      "status": "active",
      "last_sync_at": null,
      "webhook_status": "registered",
      "capabilities": {
        "realtime_orders": true,
        "fulfill_tracking": true,
        "refunds": true,
        "cancel": true,
        "messaging_mode": "email",
        "inventory_update": false,
        "reviews_feedback": true
      }
    }
  }
}
```

**Success — 200 (Shopify/eBay/Etsy/TikTok — OAuth redirect, no connection created yet):**
```json
{
  "success": true,
  "message": null,
  "data": {
    "authorization_url": "https://my-shop.myshopify.com/admin/oauth/authorize?client_id=...&scope=...&redirect_uri=...&state=..."
  }
}
```
Open this URL in a browser (see flow doc for in-app-browser vs. system-browser guidance). **No connection exists yet at this point** — it's only created if/when the merchant approves and the platform calls our server-side callback. Don't optimistically add a connection to local state here.

**Errors:**
| Status | Trigger | Message |
|---|---|---|
| 422 | `name`/`credentials.*` validation failure | Standard per-field `errors` |
| 422 | `woo` — the server tried the credentials live against the store and it failed (bad URL, bad keys, unreachable) | `"Could not connect to this WooCommerce store. Check the store URL and API keys."` under `errors.credentials` — this is a **live check**, expect it to take a second or two, show a loading state |
| 422 | Team already at `entitlements.limits.max_stores` | `"You've reached your plan's store limit ({N}). Upgrade to connect more stores."` under `errors.platform` — this is the paywall trigger from Plan §4.11, show the upgrade sheet here |
| 422 | `amazon` — always, regardless of input | A message explaining Amazon isn't available yet — show this as a permanent "coming soon" state, not a retry-able error |
| 422 | Profile setup incomplete | `"Complete profile setup before connecting a store."` — shouldn't be reachable if you're gating navigation correctly, but handle it |

---

## The OAuth callback — read this before building the Shopify/eBay/Etsy/TikTok flow

After `start` returns an `authorization_url` and you open it, the merchant approves on the platform's own site, and the platform redirects their browser to **our server** (`GET /hooks/{platform}/oauth/callback`) — not to the app. That endpoint:
- Verifies the request is genuine (signature/state checks).
- Exchanges the code for real access tokens.
- Creates the `StoreConnection` row.
- Renders a **plain HTML result page** — "Rivera Vintage Co is connected. You can return to the app now." (or a failure message) — and stops there.

**As of 2026-07-22, that result page also redirects to a custom URL scheme** — `stockbeat://oauth-callback?platform=<platform>&success=<true|false>&message=<url-encoded string>` — so the app can catch it and dismiss the browser sheet automatically instead of the merchant doing it by hand. This is **best-effort, not guaranteed**: it only works once the client side is wired up (see below), and even then a platform/OS edge case could still leave the sheet open. Practical implications for the client:
- Use an **in-app browser** (e.g. `expo-web-browser`'s `openAuthSessionAsync`, or `SFSafariViewController`/Custom Tabs) rather than the system browser — both because it's the better UX regardless of the deep link, and because `openAuthSessionAsync` specifically is what makes the redirect back to your registered scheme actually resolve the browser session's promise.
- **Register the `stockbeat://` scheme** in the app (Expo: the `"scheme"` key in `app.json`/`app.config`; bare RN: `Info.plist`/`AndroidManifest.xml`) and add a `Linking` listener for the `oauth-callback` host that reads `platform`/`success`/`message` off the query string.
- **Still keep the poll-and-diff fallback — don't remove it.** The redirect silently no-ops if the scheme isn't registered, if the OS blocks it, or on an older app build that hasn't shipped the scheme yet. When the browser sheet closes (deep-link-triggered or user-dismissed), call `GET /connections` and diff against what you had before opening the sheet as the reliability net; polling every few seconds while the sheet is open covers the case where neither the deep link nor a dismissal event fires.

---

## `GET /connections`

**Requires auth.** Lists the team's connections.

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "connections": [
      {
        "id": 1,
        "platform": "woo",
        "name": "Rivera Vintage Co",
        "status": "active",
        "last_sync_at": "2026-07-16T01:45:00.000000Z",
        "webhook_status": "registered",
        "capabilities": { "realtime_orders": true, "fulfill_tracking": true, "refunds": true, "cancel": true, "messaging_mode": "email", "inventory_update": false, "reviews_feedback": true }
      }
    ]
  }
}
```

**`status` values and what they mean for the UI:**
| Value | Meaning | Client treatment |
|---|---|---|
| `active` | Connected and syncing normally | Normal state |
| `needs_reauth` | A token expired or was revoked on the platform's side | Show a reconnect banner/badge on this connection; tapping it should restart the connect flow for that platform |
| `disconnected` | The merchant (or an admin) removed it | Shouldn't normally appear in this list — treat as if it doesn't exist if you see it |
| `paused` | Auto-paused by a plan downgrade freeze (Plan §6.4) — the team had more stores than their new plan allows | Show as read-only/inactive, with an "upgrade to restore" hint — don't offer a manual reconnect action, it comes back automatically on upgrade |

**`capabilities`** — read this per-connection to decide which quick-action buttons to render on that connection's orders (Plan §8.3). Never hardcode the platform-capability matrix client-side — it can change per adapter without an app release.
| Key | Meaning |
|---|---|
| `realtime_orders` | Orders arrive via webhook (true) vs. polling only (false) — informational, doesn't change which buttons you show |
| `fulfill_tracking` | Show the "Mark fulfilled + add tracking" quick action |
| `refunds` | Show the "Refund" quick action |
| `cancel` | Show the "Cancel order" quick action |
| `messaging_mode` | `"full"` (native in-app messaging), `"approval_gated"` (Etsy — may 422 until platform approval lands, handle gracefully), `"email"` (Shopify/Woo — goes through our own email thread, not a native message), `"template"` (Amazon — restricted, not usable yet) |
| `inventory_update` | Whether stock levels sync in real time vs. poll-only — informational |
| `reviews_feedback` | Whether the `negative_review` rule trigger has real data for this connection |

---

## `DELETE /connections/{id}`

**Requires auth**, `owner`/`manager` role only (per `GET /me`'s `team.role`).

**Success — 200:**
```json
{ "success": true, "message": "Store disconnected.", "data": null }
```
Confirm with the merchant before calling this — it's immediate and there's no undo endpoint. Existing orders from this connection stay in the feed (historical record), they just stop syncing.

**Errors:** `404` if the connection doesn't belong to the caller's team (don't leak existence of other teams' connections — same 404-not-403 pattern as elsewhere in this API).

---

## `GET /connections/{id}/health`

**Requires auth.** Plain-language status for a connection-health screen — never surface raw error codes or exception messages to the merchant.

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "connection_id": 1,
    "status": "active",
    "webhook_status": "registered",
    "last_sync_at": "2026-07-16T01:45:00+00:00",
    "message": "Rivera Vintage Co is connected and syncing normally.",
    "fix_action": null
  }
}
```
`fix_action` is a **key your client maps to a concrete flow**, not a URL or deep link — three real values today:
| `fix_action` | When | Client should |
|---|---|---|
| `null` | Healthy, or a benign informational state (never synced yet, partial webhook registration) | No button — just show `message` |
| `"reauth"` | `status === "needs_reauth"` | Show a "Reconnect" button that restarts the connect flow for that platform |
| `"reconnect"` | `status === "disconnected"` | Same restart-connect-flow action as `reauth` — StockBeat's client doesn't need to distinguish these two button-wise, just knows to re-run `start` for this platform |
| `"check_connection"` | Hasn't synced in 2+ hours despite being `active` | No user action possible — show the message ("we'll keep retrying automatically"), no button |

Treat any *other* non-null value defensively (show the `message` text, no button) since new ones could be added server-side without a client release.

**Errors:** `404` — same ownership check as `DELETE`.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success (including a "start" call for OAuth platforms — no connection created yet, just a URL) |
| 201 | Success — WooCommerce connected immediately |
| 401 | Missing/invalid/revoked bearer token |
| 404 | Connection doesn't exist or doesn't belong to your team |
| 422 | Validation failure, store limit reached, or Amazon (always) |

## Not implemented yet — do not build UI for these
- Amazon connecting at all.
- Editing an existing connection's credentials in place (WooCommerce key rotation, etc.) — disconnect and reconnect is the only path today.
