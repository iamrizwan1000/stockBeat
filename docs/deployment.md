# StockBeat — Deployment & Running Services

What must actually be **running on the server**, continuously, for the app to work end to end — not just what's in the codebase. Verified against `.env.example`, `config/horizon.php`, `composer.json`, and the actual dispatch/schedule code (not assumed from convention).

---

## TL;DR — 5 things must run continuously

| # | Process | Command | If it's down |
|---|---|---|---|
| 1 | **Web server** (PHP-FPM + Nginx/Apache/Caddy) | — (standard PHP hosting) | Nothing works at all — API, webhooks, admin panel all unreachable |
| 2 | **Redis** | managed Redis instance or `redis-server` | Horizon can't run at all; Reverb loses its scaling backend |
| 3 | **Horizon** (queue worker supervisor) | `php artisan horizon` | Orders never sync (webhooks arrive but their jobs never process), rules never fire, no push/email/SMS ever sends, digests never send |
| 4 | **Scheduler** | cron `* * * * * php artisan schedule:run` (or `php artisan schedule:work` as a persistent process) | 15-min order reconciliation stops, `unfulfilled_after_x`/`ship_by_deadline` triggers stop, digests/AI insights/trial reminders/GDPR purge all stop firing |
| 5 | **Reverb** (WebSocket server) | `php artisan reverb:start` | Support chat replies degrade to polling-on-foreground only (documented, graceful) — **the only one of the five that fails soft, not hard** |

Database (MySQL/MariaDB) is assumed as a given sixth dependency, not listed above since it's not a StockBeat-specific process.

---

## 1. Prerequisites — accounts/credentials that must exist before first deploy

All of these are real, load-bearing integrations (verified in code, not placeholders):

| Service | `.env` keys | Used for |
|---|---|---|
| MySQL/MariaDB | `DB_*` | Everything |
| Redis | `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD`, `REDIS_CLIENT=phpredis` | Queue (Horizon), Reverb scaling backend |
| Firebase (FCM) | `FIREBASE_CREDENTIALS` — a real service-account JSON file, **not committed to git**, must be uploaded to the server at the exact path this env var points to (`/var/www/html/storage/app/private/firebase/service-account.json` in `.env.example`) | Push notifications — every `SendPushNotificationAction` call needs this or it throws |
| Twilio | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_MESSAGING_SERVICE_SID` | SMS rule actions |
| Resend (or any SMTP) | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | All outbound email (rule emails, invites, support replies, data export) |
| RevenueCat | `REVENUECAT_WEBHOOK_SECRET`, `REVENUECAT_SECRET_API_KEY` | Subscription billing webhook + `POST /billing/sync` |
| Inbound email | `INBOUND_EMAIL_WEBHOOK_SECRET` | Shopify/Woo customer email replies into the Inbox |
| Reverb | `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT` | Support chat real-time delivery |
| Shopify / eBay / Etsy / TikTok Partner apps | per-platform OAuth client id/secret (see `connections-api-reference.md`) | Store OAuth connect flows |
| Amazon SP-API | not yet issued — `POST /connections/amazon/start` always 422s until this exists | Deferred, no action needed yet |

**Without the Firebase credentials file specifically**, every push send will error — this bit us in local testing (tests fail outside `ddev` for exactly this reason, since the file only exists inside the dev container). Confirm it's actually present on the server filesystem, not just referenced in `.env`.

---

## 2. One-time setup (first deploy only)

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # then fill in every real value — never deploy with example placeholders
php artisan key:generate
php artisan migrate --force
php artisan storage:link   # not currently load-bearing (packing slips stream directly, nothing else
                            # writes to the public disk today) but standard hygiene — cheap now,
                            # avoids a surprise later if something starts using Storage::disk('public')
npm install
npm run build              # compiles the admin panel's Inertia+React assets — the admin panel
                            # will 500/blank-page without this; the mobile app doesn't need it
```

---

## 3. Every deploy (release checklist)

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate   # graceful — Horizon's process manager (see §4) restarts it automatically,
                                # letting in-flight jobs finish first rather than killing them mid-run
```

**Don't skip `config:cache`/`route:cache` in production** — without them every request re-parses every config file and re-registers every route on every single request, which is meaningfully slower under real load and is exactly the kind of avoidable server strain the "efficient" ask here is about.

**After `config:cache` is run, `.env` changes require a fresh `config:cache` to take effect** — a common production gotcha (changing an env var and wondering why nothing changed).

---

## 4. Keeping Horizon, Reverb, and the scheduler alive

None of these should be started and forgotten — they need a process manager that restarts them if they crash or the server reboots. Two common approaches:

### Option A — systemd (bare VM/EC2/etc.)

```ini
# /etc/systemd/system/stockbeat-horizon.service
[Unit]
Description=StockBeat Horizon
After=network.target redis.service

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/stockbeat/artisan horizon
ExecStop=/usr/bin/php /var/www/stockbeat/artisan horizon:terminate

[Install]
WantedBy=multi-user.target
```

```ini
# /etc/systemd/system/stockbeat-reverb.service
[Unit]
Description=StockBeat Reverb
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/stockbeat/artisan reverb:start --host=0.0.0.0 --port=8080

[Install]
WantedBy=multi-user.target
```

Scheduler as a cron entry (simplest, standard Laravel approach):
```
* * * * * cd /var/www/stockbeat && php artisan schedule:run >> /dev/null 2>&1
```
Or, if the deployment platform has no cron daemon (common in some container platforms), run it as its own persistent process instead:
```ini
# /etc/systemd/system/stockbeat-scheduler.service
[Unit]
Description=StockBeat Scheduler
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/stockbeat/artisan schedule:work

[Install]
WantedBy=multi-user.target
```
(`schedule:work` is a Laravel 11 addition — a long-running process that self-manages the minute-by-minute tick, an alternative to needing a real crontab.)

### Option B — Docker / container platform

Run three separate containers/processes from the same image:
- `php artisan horizon`
- `php artisan reverb:start --host=0.0.0.0 --port=8080`
- `php artisan schedule:work`
...alongside the web (PHP-FPM+Nginx) container and a managed Redis + MySQL. Each needs its own restart policy (`restart: always` in docker-compose, or the platform's equivalent) — a container platform's own process supervisor does the "keep it alive" job that systemd does above.

### Option C — managed PaaS (Laravel Cloud, Forge, etc.)
These typically have first-class "Horizon worker" and "scheduler" process types you toggle on — if using one of these, just confirm both are actually enabled for this app (and add Reverb as a third custom process if the platform supports it, or use their managed WebSocket equivalent).

---

## 5. Horizon queue concurrency (already tuned in `config/horizon.php` — verify it matches)

Production environment block already defines per-queue concurrency caps — no changes needed unless traffic patterns change:

| Queue | Purpose | Prod `maxProcesses` | `tries` |
|---|---|---|---|
| `ingest` | Webhook order ingestion (Woo/Shopify/TikTok) | 5 | 3 |
| `poll` | Reconciliation/product/review/feedback pollers (all platforms) | 3 | 3 |
| `rules` | Rule evaluation + the actions it fires (push/email/SMS) | 5 | 3 |
| `notify-push`/`notify-email`/`notify-sms` | Admin broadcast delivery only today (see caveat below) | 5 | 3 |
| `actions` | Reserved — not currently dispatched to (fulfill/refund/cancel run synchronously in the HTTP request instead) | 3 | 3 |
| `billing` | Reserved — RevenueCat webhook processing currently runs synchronously inline, not queued (it's DB-only, no outbound call, so this is low-risk as-is) | 2 | 3 |
| `default` | Rule-driven emails (`Mail::queue()`, no explicit queue set) and anything else uncategorized | 2 | 1 |

**Known caveat, not a blocker:** rule-driven push/SMS sends run inline inside whichever `rules`-queue job triggered them, not on the dedicated `notify-*` queues — those are currently only used by admin broadcasts. This is fine at current volume (FCM/Twilio calls are fast, `rules` has 5 processes) but means a `rules`-queue backlog could theoretically also delay notification delivery, contrary to the queue-isolation design's original intent. Worth revisiting if notification volume grows significantly.

---

## 6. Health check

`GET /up` is Laravel's built-in health-check route (configured in `bootstrap/app.php`) — point your load balancer/uptime monitor here. It only confirms the web server + app boot correctly; it does **not** verify Horizon, Reverb, or the scheduler are alive — those need their own monitoring (e.g. Horizon's own dashboard at `/horizon`, or an external check that a queue actually drains).

**A cheap real-world verification after first deploy:** connect a test WooCommerce/Shopify store, place a test order, and confirm it appears in `GET /orders` within a minute or two. If webhooks are registered correctly (automatic) but Horizon isn't running, the order will simply never appear — that's the single most reliable end-to-end proof that everything above is actually wired up correctly, not just theoretically configured.
