# StockBeat Mobile — Auth API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`

Every response, success or failure, shares one envelope:
```json
{ "success": true|false, "message": string|null, "data": object|null, "errors": object|null }
```
`errors` is only present on validation failures (422) and has the shape `{"field_name": ["message 1", "message 2"]}`. **Important:** on a 422, the top-level `message` is a generic string ("The given data was invalid.") — the actual user-facing text you want to show lives inside `errors.<field>[0]`, not `message`. Always read from `errors`, never assume `message` is display-ready for 422s.

Authenticated endpoints require `Authorization: Bearer {token}`. A missing/invalid token returns `401` with `{"success": false, "message": "Unauthenticated.", "data": null}`.

---

## `POST /auth/otp/request`

Unauthenticated. Sends a 6-digit code by email.

**Request body:**
```json
{ "email": "jamie@example.com" }
```
| Field | Rules |
|---|---|
| `email` | required, valid email format, max 255 chars |

**Success — 200** (always, regardless of whether the email is registered):
```json
{ "success": true, "message": "A verification code has been sent if the email is registered.", "data": null }
```

**Errors:**
| Status | Trigger | Shape |
|---|---|---|
| 422 | Malformed email (fails format/max-length) | `{"errors": {"email": ["The email field must be a valid email address."]}}` |
| 422 | Resend requested within 30s of the last code for this email | `{"errors": {"email": ["Please wait a moment before requesting another code."]}}` |
| 429 | Route-level throttle: **3 requests per 10 minutes**, keyed by `ip + email` together (not a global per-email or global per-IP cap) | `{"message": "Too many requests.", "data": null}` |

**Notes:**
- Every call invalidates any previously-unconsumed code for that email and issues a brand new one (old codes stop working the instant a new one is requested).
- The code itself expires **10 minutes** after issuance.

---

## `POST /auth/otp/verify`

Unauthenticated. Verifies the code and returns a bearer token.

**Request body:**
```json
{ "email": "jamie@example.com", "code": "482913" }
```
| Field | Rules |
|---|---|
| `email` | required, valid email |
| `code` | required, exactly 6 digits |

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "token": "1|abcdef1234567890...",
    "is_new_user": false,
    "user": {
      "id": 1,
      "name": "Jamie Rivera",
      "email": "jamie@example.com",
      "business_name": "Rivera Vintage Co",
      "base_currency": "AUD",
      "timezone": "Australia/Sydney",
      "sells_on": ["woo"]
    }
  }
}
```
For a genuinely brand-new user, `user` fields other than `id`/`email` will be empty/null (`name` is `""`, `base_currency` defaults to `"USD"`, everything else `null`) — expected, `ProfileSetupScreen` fills these in next.

**Errors (all 422, distinguish by the specific message under `errors.code[0]` or `errors.email[0]`):**
| Message (inside `errors.code` or `errors.email`) | Meaning | What the client should do |
|---|---|---|
| `"This code is invalid or has expired."` | No active code found for this email, or it's past its 10-min expiry | Prompt to request a new code |
| `"This code is incorrect."` | Wrong digits — one of 5 attempts consumed | Let them retry the same code entry |
| `"Too many attempts. Please request a new code."` | 5th wrong attempt hit — code now locked | Force a new code request, same UI as "expired" |
| `"This account has been deleted. Contact support if you believe this is a mistake."` | Email matches a soft-deleted account | Show a distinct "contact support" state — do not offer retry |

| Status | Trigger |
|---|---|
| 429 | Route-level throttle: **10 verify attempts per minute, keyed by IP only** (separate from the 5-per-code lockout above, which is keyed by the specific OTP code) |

**Token format:** a Laravel Sanctum plain-text token (`{id}|{40-char-string}`). Store it whole; send it back verbatim as `Authorization: Bearer {token}`. It does not expire server-side (Sanctum tokens are long-lived by default) — it's only invalidated by explicit logout, "logout all devices," account suspension, or account deletion.

---

## `POST /profile/setup`

**Requires auth.** One-time step for new users. Idempotent-ish — calling it again just updates the same fields (it does not create a second team).

**Request body:**
```json
{
  "name": "Jamie Rivera",
  "business_name": "Rivera Vintage Co",
  "phone": "+14155552671",
  "sells_on": ["shopify", "woo"],
  "timezone": "Australia/Sydney",
  "base_currency": "AUD"
}
```
| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `business_name` | optional, string, max 255 |
| `phone` | optional, must match `^\+[1-9]\d{1,14}$` (E.164) |
| `sells_on` | required, array, min 1 item; each item must be one of `shopify` `woo` `ebay` `etsy` `amazon` `tiktok` |
| `timezone` | optional, must be a valid IANA timezone identifier |
| `base_currency` | optional, exactly 3 characters |

**Success — 200:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "user": {
      "id": 1, "name": "Jamie Rivera", "email": "jamie@example.com",
      "business_name": "Rivera Vintage Co", "base_currency": "AUD",
      "timezone": "Australia/Sydney", "sells_on": ["shopify", "woo"]
    }
  }
}
```
Note: **no `team` or `entitlements` in this response** — call `GET /me` right after to get those (a `Team` and owner membership are created behind the scenes on this call, but this endpoint doesn't return them).

**Errors:** standard 422 per-field validation errors only, e.g.:
```json
{ "success": false, "message": "The given data was invalid.", "errors": { "sells_on": ["The sells_on field is required."] } }
```

---

## `GET /me`

**Requires auth.** The client's one call to get full app state after login/launch — call this after every OTP verify (existing users) and after every profile setup, and again on every cold app start if a token is stored. This is the **only** endpoint that returns `feature_flags`, `sms_topup_packs`, and `content` — nothing else exposes them, so don't skip re-fetching it just because you already have `user`/`team` cached locally.

**Success — 200, profile setup already complete:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "user": { "id": 1, "name": "Jamie Rivera", "email": "jamie@example.com", "business_name": "Rivera Vintage Co", "base_currency": "AUD", "timezone": "Australia/Sydney", "sells_on": ["woo"] },
    "team": { "id": 1, "name": "Rivera Vintage Co", "role": "owner" },
    "entitlements": {
      "plan": "pro",
      "subscription_status": "active",
      "trial_ends_at": null,
      "limits": {
        "max_stores": 10,
        "max_rules": null,
        "sms_monthly": 100,
        "email_monthly": 1000,
        "history_days": 365,
        "team_seats": 3,
        "trial_days": null,
        "inbox_enabled": true,
        "analytics_level": "full",
        "widgets_enabled": true,
        "advanced_triggers_enabled": false,
        "ai_enabled": true,
        "ai_questions_monthly": 150,
        "ai_rule_builder_enabled": true,
        "ai_proactive_insights_enabled": false
      },
      "sms_balance": 42
    },
    "feature_flags": { "new_rules_ui": true },
    "sms_topup_packs": [
      { "key": "sms_100", "name": "100 SMS", "sms_credits": 100, "price_usd": "2.99" },
      { "key": "sms_500", "name": "500 SMS", "sms_credits": 500, "price_usd": "9.99" }
    ],
    "content": { "paywall_pro_headline": "..." },
    "needs_profile_setup": false
  }
}
```

**Success — 200, profile setup still pending:**
```json
{
  "success": true,
  "message": null,
  "data": {
    "user": { "id": 1, "name": "", "email": "jamie@example.com", "business_name": null, "base_currency": null, "timezone": null, "sells_on": null },
    "team": null,
    "entitlements": null,
    "feature_flags": null,
    "sms_topup_packs": [],
    "content": {},
    "needs_profile_setup": true
  }
}
```

**Routing rule:** always branch navigation on `needs_profile_setup`, never on whether `team`/`entitlements` are present (they're just null together when setup is pending — same signal, but `needs_profile_setup` is the explicit one to check).

`role` inside `team` is one of `owner` `manager` `agent` `viewer` — determines which write actions the UI should allow (see the Team/roles spec, not part of auth).

**`entitlements.limits` — read every plan/feature gate from here, never hardcode a plan's numbers client-side** (they're admin-editable server-side and change without an app release):
| Key | Type | Meaning |
|---|---|---|
| `max_stores` | int\|null | Connected-store cap. `null` = unlimited. |
| `max_rules` | int\|null | Custom rule cap. `null` = unlimited. |
| `sms_monthly` | int | SMS alerts included per month. |
| `email_monthly` | int | Email alerts included per month. |
| `history_days` | int\|null | How far back the order feed goes. `null` = unlimited. |
| `team_seats` | int | Max team members. |
| `trial_days` | int\|null | Only meaningful on the Premium plan row server-side; irrelevant once `subscription_status` isn't `trial`. |
| `inbox_enabled` | bool | Show/hide the Inbox tab. |
| `analytics_level` | `"today"` \| `"7d"` \| `"full"` | Which `range` values `GET /analytics/summary` will accept — calling with a disallowed range 422s. |
| `widgets_enabled` | bool | Home-screen widget entitlement. |
| `advanced_triggers_enabled` | bool | Gates `order_spike`/`refund_spike` in the rule-trigger picker. |
| `ai_enabled` | bool | Gates the **Data Copilot** — if `false`, still show App Help (it's ungated on every plan, see the AI Assistant spec), but hide/lock the "ask about your data" surface. |
| `ai_questions_monthly` | int\|null | Data Copilot's monthly question cap. `null` = unlimited. |
| `ai_rule_builder_enabled` | bool | Gates the natural-language rule builder entry point. |
| `ai_proactive_insights_enabled` | bool | Whether unprompted AI insight notifications are possible for this team (nothing to build differently client-side — this only affects whether the *server* ever sends one). |

`entitlements.sms_balance` is **not** in `limits` — it's a sibling key, the team's *current* SMS credit balance (already spent this cycle), distinct from `limits.sms_monthly` (the *allotment*).

**Errors:** `401` if the token is invalid/revoked — clear local token and return to `WelcomeScreen`.

---

## `POST /devices`

**Requires auth.** Registers a push notification token. Call this once after profile setup (new users) or on every app launch after confirming push permission is granted (cheap to re-call — it upserts).

**Request body:**
```json
{ "platform": "ios", "push_token": "expo-push-token-or-fcm-token-string" }
```
| Field | Rules |
|---|---|
| `platform` | required, one of `ios` `android` |
| `push_token` | required, string, max 255 |

**Success — 201:**
```json
{ "success": true, "message": null, "data": { "device": { "id": 1, "platform": "ios", "last_seen_at": "2026-07-18T02:00:00.000000Z" } } }
```

---

## `POST /auth/logout`

**Requires auth.** Revokes only the token used on this request (i.e., this device/session).

**Request body:** none.

**Success — 200:**
```json
{ "success": true, "message": "Logged out.", "data": null }
```
Client action: delete the locally stored token regardless of response body, navigate to `WelcomeScreen`.

---

## `POST /auth/logout-all`

**Requires auth.** Revokes every Sanctum token for the user — every device gets signed out, not just this one.

**Request body:** none.

**Success — 200:**
```json
{ "success": true, "message": "Logged out of all devices.", "data": null }
```

---

## Quick reference — status codes you'll see across all of these

| Status | Meaning here |
|---|---|
| 200/201 | Success |
| 401 | Missing/invalid/revoked bearer token (authenticated endpoints only) |
| 422 | Validation failure — read `errors.<field>[0]` for display text |
| 429 | Rate limited — generic "too many requests" messaging is fine, no need to parse further |

## Not implemented yet — do not build UI for these
- Apple/Google/social sign-in (`POST /auth/social` does not exist)
- Password reset / forgot password (there is no password, ever)
- Email verification as a separate step (the OTP itself is the verification)
