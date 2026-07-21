# StockBeat Mobile — Connect-a-Store Flow Screens

Depends on the Auth flow completing first (`auth-flow-screens.md`, Screen 3 `ProfileSetupScreen`) — this is the hand-off point Plan's onboarding diagram calls "Screen 4." Pair with `connections-api-reference.md` for exact request/response shapes — **read that doc's "OAuth callback" section before building Screens 2–3 below**, since the no-deep-link behavior changes how you'd normally build this.

---

## Screen 1 — `ConnectStoreScreen` (platform picker)

**Reached from:** right after `ProfileSetupScreen` succeeds (first-run onboarding), or from Settings → "Connect another store" (returning users, subject to the plan's `max_stores` limit).

**Purpose:** pick which platform to connect.

**Content:**
- A grid/list of 6 platform tiles: Shopify, WooCommerce, eBay, Etsy, Amazon, TikTok Shop.
- **Order the tiles by the user's `sells_on` answer from profile setup first**, remaining platforms after — the platforms they told you they sell on are the ones they'll want, don't make them scroll past ones they didn't pick.
- **Amazon tile: show it, but visually distinct (greyed/"Coming soon" badge) and non-interactive**, or route it to a static "Amazon support is coming soon" screen rather than attempting to start a connection — the backend will 422 every time today (see API reference), don't build a flow around retrying that.
- Each tile shows platform name + icon; no live status here (that's Screen 5 / the connections list).

**On tap of a platform tile:**
- `woo` → `ConnectWooScreen` (Screen 2a)
- `shopify` → `ConnectShopifyScreen` (Screen 2b) — collects `shop_domain` first
- `ebay` / `etsy` / `tiktok` → straight to the OAuth browser step (Screen 3) — no data entry needed first
- `amazon` → the "coming soon" screen, no API call

---

## Screen 2a — `ConnectWooScreen`

**Purpose:** collect WooCommerce REST API credentials. This is the one platform with no browser hand-off — everything happens in-app.

**Inputs:**
| Field | Type | Notes |
|---|---|---|
| `name` | text | Pre-fill from business name (profile setup), editable |
| `store_url` | text/URL input | `keyboardType="url"`, `autoCapitalize="none"`. Must be a full URL (`https://shop.example.com`) — helper text should say so |
| `consumer_key` | text | `autoCapitalize="none"`, `autoCorrect={false}` — these are long opaque strings, consider a paste-friendly field, not a "type it" UX |
| `consumer_secret` | text, obscured like a password field | Same paste-friendly treatment |

**Helpful context to show on this screen** (not from the API — just good onboarding UX): a short "how to get your keys" hint or link, since generating WooCommerce REST API keys requires the merchant to go into their own WordPress admin (WooCommerce → Settings → Advanced → REST API) — this is real friction, acknowledge it rather than assuming they already have keys ready.

**On submit:**
- Call `POST /connections/woo/start` with `{name, credentials: {store_url, consumer_key, consumer_secret}}`.
- **This is a live check** — the server actually calls the merchant's store before responding. Show a loading state, this can take a couple seconds, don't let the button look frozen.
- **Success (201):** connection is already active, no further step — navigate straight to Screen 4 (`ConnectionSuccessScreen`) with the returned `connection`.
- **Error (422, `errors.credentials`):** show the message inline, let them fix and resubmit in place — don't clear the fields.

---

## Screen 2b — `ConnectShopifyScreen`

**Purpose:** collect just the shop domain before handing off to Shopify's own OAuth page.

**Inputs:**
| Field | Type | Notes |
|---|---|---|
| `name` | text | Pre-fill from business name, editable |
| `shop_domain` | text | Must end up matching `{store}.myshopify.com`. If the merchant only knows their custom domain, consider accepting either and normalizing — but the request must ultimately send the `.myshopify.com` form, the server regex requires it exactly |

**On submit:** call `POST /connections/shopify/start` → on success (200, `authorization_url` present), go straight to Screen 3.

---

## Screen 3 — OAuth browser step (Shopify / eBay / Etsy / TikTok)

**No dedicated screen name** — this is a transient step, not a persistent screen in your navigation stack.

**Do:**
- Open `authorization_url` in an **in-app browser session** (`expo-web-browser`'s `openAuthSessionAsync`/`openBrowserAsync`, or platform-native `SFSafariViewController`/Chrome Custom Tabs) — not the system browser app, and not a plain in-app `WebView` (platforms increasingly reject WebView-based OAuth for security reasons).
- Show a loading/transition state while the browser sheet opens.

**Critical — read `connections-api-reference.md`'s OAuth callback section before writing this:** there is **no deep link back into the app**. The merchant approves on the platform's page, gets redirected to a plain result webpage saying "you can return to the app now," and then has to manually dismiss the browser sheet themselves.

**What to actually build:**
1. Open the browser session.
2. While it's open, poll `GET /connections` every few seconds (cheap, no documented rate limit) comparing against a snapshot taken right before opening the sheet.
3. The moment a new connection for the expected platform appears, **programmatically close the browser sheet** (most in-app-browser libraries support this) and navigate to Screen 4 with that connection.
4. Also handle the browser sheet being dismissed by the user manually (swipe-down, back button) — on dismissal, do one final `GET /connections` check: new connection present → Screen 4; nothing new → Screen "connection not completed," offering to retry (back to Screen 1) rather than silently returning to wherever they were.
5. Reasonable timeout (e.g. 2 minutes of polling with nothing new) → treat as abandoned, same "not completed" state as above.

This polling approach is a deliberate, real workaround for a real, current backend gap — not a guess. Don't build a `Linking.addEventListener` deep-link handler for this; there's nothing that will ever fire it.

---

## Screen 4 — `ConnectionSuccessScreen`

**Params:** the newly created `connection` object.

**Purpose:** confirm the connection worked, and immediately push toward the next onboarding step.

**Content:** "✅ {connection.name} connected!" — then per Plan §4.1.1's guided first-run ("connect store → enable push → see first order"), prompt for push notification permission here if this is the first-run flow (see `auth-flow-screens.md` Screen 3's note on `POST /devices`), then navigate into the main app (Feed).

**If this was reached from Settings** (not first-run onboarding), skip the push-permission prompt (already handled) and just return to the connections list (Screen 5) or Settings.

---

## Screen 5 — `ConnectionsListScreen` (Settings → Connected Stores)

**Purpose:** manage existing connections — not part of first-run onboarding, but the natural home screen for this whole module afterward.

**Content:** `GET /connections`, one row per connection:
- Platform icon + `name`.
- Status badge: green "Connected" (`active`), amber "Needs attention" (`needs_reauth`), grey "Paused" (`paused`) — see the API reference's full status table.
- Tapping a row → `ConnectionHealthScreen` (Screen 6).
- A "Connect another store" button → Screen 1, **only enabled if under `entitlements.limits.max_stores`** (from `GET /me`) — if at the limit, tapping it should open the upgrade paywall instead of Screen 1 (the server would 422 anyway, but don't make them submit a form to find out).

**Disconnect action:** swipe-to-delete or an overflow menu → confirm dialog ("Disconnect {name}? Historical orders stay, but it'll stop syncing.") → `DELETE /connections/{id}` → remove from list on success.

---

## Screen 6 — `ConnectionHealthScreen`

**Params:** `connection_id`.

**Purpose:** the plain-language diagnostic screen from Plan §4.1.1.

**On load:** call `GET /connections/{id}/health`.

**Content:** show `message` as the primary text, `last_sync_at` as a relative timestamp ("Last synced 2 hours ago"), and a button driven by `fix_action`:
| `fix_action` | Button |
|---|---|
| `null` | No button |
| `"reauth"` / `"reconnect"` | "Reconnect" → restart the connect flow for this platform (back to Screen 1's platform-specific path — Screen 2a/2b/3 as appropriate) |
| `"check_connection"` | No button — this state resolves itself, don't offer a manual retry that doesn't exist server-side |

---

## Edge case: app killed/backgrounded mid-OAuth

If the OS kills the app while the in-app browser sheet is open (rare but possible on Android), on next launch your normal session-restore flow (`auth-flow-screens.md`'s "App-launch / session-restore flow") will call `GET /me` and land on the Feed or wherever `needs_profile_setup` routes to. **You won't know whether the OAuth grant that was in progress actually succeeded.** There's no pending-connection state exposed by the API to recover this gracefully today — the practical fallback is that `GET /connections` on the Feed/Connections screen will simply show the connection if it succeeded, or not if it didn't; there's nothing more precise to build against right now.

## Edge case: `needs_reauth` appearing for an already-connected store

This isn't part of the connect flow itself but will show up anywhere connections are listed (Feed header, Settings) — a token can expire or be revoked on the platform's side at any time, independent of anything the user does in the app. Surface it as a persistent, dismissible-but-recurring banner rather than a one-time toast, since it needs action, not just acknowledgment.
