# StockBeat Mobile — Auth Flow Screens

Reflects what's actually implemented on the backend today (verified against `app/Http/Controllers/Api/V1/Auth/*`, `app/Actions/Auth/*`). No password, ever. No Apple/Google sign-in yet — omit those buttons entirely; there is no `/auth/social` endpoint built, so a button for it would be a dead end.

Pair this with `auth-api-reference.md` for exact request/response shapes.

---

## Screen 0 — app launch gate (before any UI, including before checking if a token is stored)

**On every cold start**, before rendering `WelcomeScreen`, a stored-token check, or anything else: call `GET /config` (`auth-api-reference.md` — **unauthenticated, no bearer token needed or sent**, this must be checkable before the user has ever signed in).

```json
{ "success": true, "message": null, "data": {
  "min_version": "1.2.0",
  "maintenance_mode": false,
  "maintenance_banner": null
} }
```

- **`min_version`** — if the running app's version is below this (semver compare), show a full-screen, non-dismissible "Update required" screen with a link to the store listing. Don't let the user navigate past it, even with a valid stored token — a killed-version client shouldn't be able to hit the rest of the API expecting old behavior. `null` means no enforced minimum, skip this check.
- **`maintenance_mode`** — if `true`, show a full-screen "We'll be right back" state (using `maintenance_banner` as the message if present, a generic fallback if `null`) instead of proceeding to login/the main app shell. Don't cache this as "safe" for more than the current app session — re-check on every cold start, and consider a periodic re-check (e.g. on app foreground) if a maintenance window could start while the app is already open.
- Neither field has any relation to `entitlements`/plan gating — this is infra-level (is the app itself usable right now), not billing-level. Once both checks pass, proceed to the normal launch flow: check for a stored token → `GET /me` if present (`auth-api-reference.md`) → `WelcomeScreen` if not.
- **If this call fails entirely** (network error, 5xx): don't hard-block the app on it — fail open and proceed to the normal launch flow. A config-check outage shouldn't be able to lock every user out of an otherwise-working app.

---

## Screen 1 — `WelcomeScreen`

**Purpose:** collect an email address. This single screen handles both login and signup — the user never picks which one.

**Inputs:**
| Field | Type | Notes |
|---|---|---|
| `email` | text input | `keyboardType="email-address"`, `autoCapitalize="none"`, `autoCorrect={false}`, `autoComplete="email"` |

**Client-side validation:** basic email-format check only, to enable/disable the button — the server is the real authority (`required|email|max:255`).

**Primary action:** "Continue" button, disabled until the field looks like a valid email.

**On submit:**
- Call `POST /auth/otp/request`.
- Always succeeds (200) regardless of whether the email is registered — never show "no account found." This is deliberate (Plan §4.1: no account enumeration).
- Show a loading spinner on the button while in flight.
- On success → navigate to `OtpVerificationScreen`, passing `email`.
- On error → see error states in the API reference (rate limits, resend cooldown). Show inline under the field.

**Do not build:** "Continue with Apple" / "Continue with Google" buttons. No backend route exists for either yet.

---

## Screen 2 — `OtpVerificationScreen`

**Params received:** `email` (string, from Screen 1).

**Purpose:** enter the 6-digit code just emailed.

**Inputs:**
| Field | Type | Notes |
|---|---|---|
| `code` | 6 individual digit boxes (or one 6-digit field) | Numeric keypad. Must support pasting a full 6-digit string from the clipboard into the first box and having it fill all six — code is email-delivered, not SMS, so OS-level SMS autofill doesn't apply, but clipboard paste should still work smoothly. |

**Behavior:**
- Auto-submit the moment all 6 digits are entered (don't make the user tap a separate "Verify" button — Plan's whole pitch is a ~15-second login).
- Show the target email above the code boxes (e.g. "Code sent to jamie@example.com") with a "Wrong email? Go back" link.
- **Resend:** a "Resend code" link, disabled/greyed out for the first 30 seconds after this screen loads (server enforces this same 30s cooldown — see API reference), then becomes tappable and re-calls `POST /auth/otp/request` for the same email.

**On submit:**
- Call `POST /auth/otp/verify {email, code}`.
- **Success (200):** response includes `token`, `is_new_user`, `user`.
  - Store `token` in secure storage (Keychain on iOS / Keystore-backed on Android — not plain MMKV).
  - If `is_new_user === true` → navigate to `ProfileSetupScreen`.
  - If `is_new_user === false` → call `GET /me`, then navigate straight into the main app (Feed). Don't skip this `GET /me` call — it's what tells you `needs_profile_setup`, team info, and entitlements; `is_new_user: false` alone isn't enough to assume setup is complete (see edge case below).
- **Errors (422)** — four distinct messages the server can return under `errors.code[0]` or `errors.email[0]`, each needs distinct handling, not one generic "wrong code" toast:
  1. *Invalid or expired* — no active code found, or the 10-minute window passed → prompt to request a new code (surface the "Resend" action prominently).
  2. *Incorrect code* — wrong digits, one attempt consumed (5 max) → let them retry in place, show attempts remaining if you want to be helpful (server doesn't return a count — track client-side optimistically, or just say "incorrect, try again").
  3. *Too many attempts* — 5th wrong attempt hit, code is now locked → must request a new code, same UI as case 1.
  4. *Account deleted* — matches a soft-deleted account → do **not** show a generic error; show a distinct "This account was deleted — contact support" screen/state with no retry path.
- **429 (rate limited):** generic "Too many attempts, please wait a moment" — this is a route-level throttle (10 verify attempts/min across all codes for this IP), separate from the 5-attempt-per-code lockout above.

---

## Screen 3 — `ProfileSetupScreen` (new users only)

**Requires:** the bearer token from Screen 2 (this endpoint is authenticated).

**Purpose:** the one-screen onboarding form — name, business, where they sell.

**Inputs:**
| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | text | ✅ | Max 255 chars |
| `business_name` | text | optional | Max 255 chars |
| `phone` | phone input w/ country code | optional | Must produce **E.164 format** (`+14155552671`) — use a library that outputs this directly, the server regex is strict (`^\+[1-9]\d{1,14}$`) |
| `sells_on` | multi-select chips | ✅, min 1 | Exactly these six values — **note the value is `woo`, not `woocommerce`**: `shopify` `woo` `ebay` `etsy` `amazon` `tiktok`. Display labels can say "Shopify," "WooCommerce," "TikTok Shop," etc. — just send the raw key. |
| `timezone` | searchable picker | optional | Must be a valid IANA identifier (e.g. `Australia/Sydney`). Auto-detect from the device (`Intl.DateTimeFormat().resolvedOptions().timeZone` on RN/Hermes) and pre-fill, but let the user change it. |
| `base_currency` | currency picker | optional | Exactly 3 letters (e.g. `USD`, `AUD`). Auto-detect from device locale, pre-fill, editable. |

**Primary action:** "Continue" / "Finish setup."

**On submit:**
- Call `POST /profile/setup` with all fields.
- Response is just the updated `user` object — **follow up with `GET /me`** to get `team`, `entitlements`, and confirm `needs_profile_setup: false` before moving on. Don't assume success from this response alone.
- Validation errors are standard per-field 422s — show inline under each field.

**Right after this screen succeeds** (good place, not strictly part of this endpoint): prompt for push notification permission and call `POST /devices` with the platform + push token, per Plan §4.1.1's guided first-run ("connect store → enable push → see first order").

**Then:** hand off to the store-connection flow (Screen 4 in Plan's onboarding diagram — "connect your first store," chips pre-ordered by the `sells_on` answer). That's Connections-API territory, not part of this auth spec — see the Connections endpoints separately.

---

## Existing-user path (skips Screen 3 entirely)

`is_new_user: false` on verify → call `GET /me` → if `needs_profile_setup: false`, go straight to the Feed. No profile screen shown.

## Edge case: returning to a half-finished signup

If `GET /me` ever returns `needs_profile_setup: true` for a user who verified successfully (e.g. they closed the app mid-onboarding on a previous session, or `is_new_user` was `false` because the account technically existed but never completed setup), route them to `ProfileSetupScreen`, not the Feed. Always trust `needs_profile_setup` from `GET /me` over `is_new_user` from the verify response.

## App-launch / session-restore flow

On cold start:
1. Check secure storage for a stored token.
2. **No token** → `WelcomeScreen`.
3. **Token present** → call `GET /me` immediately.
   - `200`, `needs_profile_setup: false` → Feed.
   - `200`, `needs_profile_setup: true` → `ProfileSetupScreen`.
   - `401` (token revoked — force-logout, account deleted, etc.) → clear the stored token, go to `WelcomeScreen`.

## Logout

- Settings → "Log out" → `POST /auth/logout` → clear local token → `WelcomeScreen`.
- Settings → "Log out of all devices" → `POST /auth/logout-all` → same client-side handling.

Both endpoints require the bearer token and return `{success: true}` with no meaningful data payload — treat any 200 as success and clear local state regardless of body content.
