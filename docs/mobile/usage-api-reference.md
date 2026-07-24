# StockBeat Mobile — Usage History API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

Companion to `GET /me`/`GET /billing/entitlements` (`settings-api-reference.md`'s Billing & subscription section), which only report the **current-standing balance** (`sms_balance`/`ai_questions_remaining`/`emails_remaining`). This endpoint adds **how much of this month's allotment is used** and a **30-day daily breakdown** suitable for a usage graph — build any usage-history screen or chart against this endpoint, not by trying to derive it from the point-in-time entitlements fields, which don't carry enough information to reconstruct it.

Added 2026-07-24, closing a previously real gap (there was no usage-history/ledger endpoint at all before this).

---

## `GET /usage/summary`

**Requires auth.** No query params — always returns all three channels together in one call.

```json
{ "success": true, "message": null, "data": {
  "sms": {
    "balance": 98, "plan_monthly_allotment": 100, "used_this_month": 2,
    "pct_used": 2.0, "quota_warning": false,
    "daily": [ { "date": "2026-06-25", "count": 0 }, { "date": "2026-07-24", "count": 2 } ]
  },
  "ai_questions": {
    "limit": 150, "used_this_month": 12, "remaining": 138,
    "pct_used": 8.0, "quota_warning": false,
    "daily": [ { "date": "2026-06-25", "count": 0 }, { "date": "2026-07-24", "count": 1 } ]
  },
  "emails": {
    "limit": 1000, "used_this_month": 220, "remaining": 780,
    "pct_used": 22.0, "quota_warning": false,
    "daily": [ { "date": "2026-06-25", "count": 0 }, { "date": "2026-07-24", "count": 6 } ]
  }
} }
```

### Field-by-field

| Field | Type | Meaning |
|---|---|---|
| `sms.balance` | int | The **real, spendable wallet balance** — same value as `GET /me`'s `entitlements.sms_balance`. Top-up credits never expire, so this is the number that actually matters for "can I still send SMS." |
| `sms.plan_monthly_allotment` | int\|null | The plan's intended monthly SMS credit (e.g. 100 on Pro) — **informational only**, see the caveat below. |
| `sms.used_this_month` / `.ai_questions.used_this_month` / `.emails.used_this_month` | int | Count of real sends/questions/emails since the 1st of the current calendar month. |
| `ai_questions.limit` / `emails.limit` | int\|null | The **effective** monthly cap — plan allotment plus any same-calendar-month top-up bonus (AI only; there's no email top-up product). `null` means unlimited. This is the exact same effective-limit computation already backing `ai_questions_remaining`/`emails_remaining` on `/me` — there is no second, competing notion of the cap. |
| `ai_questions.remaining` / `emails.remaining` | int\|null | `max(limit - used_this_month, 0)`, or `null` when `limit` is `null`. **SMS has no `remaining` field** — use `sms.balance` instead, since SMS isn't a hard monthly reset. |
| `pct_used` | float\|null | `round(used_this_month / limit * 100, 1)`, capped at 100. **`null` whenever the relevant limit is `0` or `null`** — there's no meaningful percentage to show for a fully-locked or fully-unlimited plan (e.g. Free's `ai_questions_monthly` is `0`, not `null`, since AI is locked entirely rather than unlimited). |
| `quota_warning` | bool | `true` once `pct_used >= 80`. **Compute nothing client-side** — this flag is the single source of truth for "show the running-low prompt." |
| `daily` | array | Always **exactly 30 entries, oldest first, one per calendar day, zero-filled** for days with no activity. Safe to feed straight into a bar/line chart — no gap-filling needed client-side. |

### ⚠️ `sms.balance` vs. `sms.pct_used` — two different numbers, don't conflate them

`balance` is the real wallet (what actually gets debited on send, what a top-up purchase actually credits). `used_this_month`/`pct_used` are computed **only** against `plan_monthly_allotment` for an "at a glance, how much of my included monthly SMS have I used" indicator — they say nothing about the wallet's real remaining balance, which could be much higher (unspent top-up credit) or, in principle, lower than the plan's monthly figure would suggest.

**Known gap this endpoint's numbers rely on:** the monthly SMS grant that's supposed to top the wallet back up each billing cycle (Plan §5's "unused monthly credits do not roll over") isn't actually dispatched anywhere in the backend yet — `SmsLedger::REASON_MONTHLY_GRANT` exists as a reason code but nothing ever creates a ledger row with it. So `plan_monthly_allotment`/`sms.pct_used` describe the *intended* behavior, not something the wallet is currently kept in sync with automatically. Treat SMS's percentage as informational/approximate until that job exists; AI questions' and email's percentages have no such caveat — both are computed directly from real usage this month against a real, correctly-enforced limit.

### Errors

| Status | Meaning |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 422 | `"Complete profile setup first."` — same guard every other authenticated endpoint uses; shouldn't be reachable in normal navigation since `needs_profile_setup` gates entry into the main app shell |

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 422 | Profile setup incomplete |
