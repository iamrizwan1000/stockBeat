# StockBeat Mobile — Reviews API Reference

Base URL: `https://stockbeat.qistpay.org/api/v1`. Same envelope and auth rules as `auth-api-reference.md`.

**A real gap this closes:** `reviews` existed server-side purely to feed the `negative_review` rule trigger (`notifications-flow-screens.md`/`rules-api-reference.md`) — there was no way to even list reviews from the mobile app before this, let alone reply to one. Both endpoints below are new as of 2026-07-26.

**Reply support: eBay only, v1.** WooCommerce and eBay are the only platforms with real review **data** today (Shopify/Etsy/Amazon declare no fetch code and never populate this list for those connections). Of those two, only **eBay** can actually reply — verified against eBay's Commerce Feedback API before this was built. **WooCommerce reviews can be listed but not replied to** — its `wc/v3` REST API has no reply mechanism at all (`comment_parent` is hardcoded to `0` on every review), and the real path (`wp/v2/comments`) needs a WordPress credential this app doesn't collect. This is explicitly deferred (Plan §4.15), not a bug — don't file it as one.

---

## `GET /reviews`

**Requires auth.** No pagination, no filters — returns the team's entire polled review list, newest first.

```json
{ "success": true, "message": null, "data": { "reviews": [
  { "id": 1, "connection_id": 1, "product_title": "110445566778", "rating": 1, "reviewer_name": "buyer99", "content": "Item arrived late.", "reviewed_at": "2026-07-24T10:15:00Z" }
] } }
```

| Field | Type | Notes |
|---|---|---|
| `connection_id` | int | Cross-reference `GET /connections` for the store name and — critically — the `platform`, since that's what determines whether the reply control should even show (see below) |
| `product_title` | string\|null | For eBay this is actually the `item_id`, not a human title — eBay's Trading API feedback payload doesn't carry the listing title, only the item id, so don't be surprised it looks like a number |
| `rating` | int | 1–5. eBay negative feedback is always mapped to `1` here (eBay's feedback system itself is binary positive/negative/neutral, not a star rating — this is a lossy but honest simplification, not eBay's real scale) |
| `reviewer_name` | string | The buyer's platform username — this is also what gets sent as the reply's recipient, see below |
| `content` | string | The review/feedback text itself |
| `reviewed_at` | string | ISO 8601 |

**Only show a "Reply" control when the review's connection platform is `eBay`** (cross-reference `connection_id` against `GET /connections`'s `platform` field) — WooCommerce reviews should render read-only, every other platform won't even appear in this list yet.

## `POST /reviews/{id}/reply`

**Requires auth**, `owner`/`manager` role.

```json
{ "body": "Sorry about that, we shipped a replacement today." }
```

| Field | Rules |
|---|---|
| `body` | required, string, max 2000 |

**Success — 200:**
```json
{ "success": true, "message": "Reply sent.", "data": { "review": {
  "id": 1, "connection_id": 1, "rating": 1, "content": "Item arrived late."
} } }
```

**422 if the channel doesn't support replies** (anything but eBay):
```json
{ "success": false, "message": "The given data was invalid.", "errors": { "review": ["This channel doesn't support replying to reviews from here."] } }
```

Same "hide the control rather than let them hit this wall" rule as every other capability-gated action in this app (`products-api-reference.md`'s stock endpoint, `orders-api-reference.md`'s quick actions) — this 422 is a safety net for a client that got the platform check wrong, not something a correctly-built UI should ever actually trigger.

**404** if the review doesn't belong to your team.

---

## Quick reference

| Status | Meaning here |
|---|---|
| 200 | Success |
| 401 | Missing/invalid/revoked bearer token |
| 403 | `viewer`/`agent` role attempting to reply |
| 404 | Review doesn't belong to your team |
| 422 | `body` fails validation, or the channel doesn't support replies (see above) |
