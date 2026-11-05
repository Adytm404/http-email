# mailroute

A self-hosted email router. Your apps call **one HTTP endpoint** (Resend-compatible), and mailroute delivers through **your own** SMTP servers, Resend, SendGrid or Mailgun accounts, picking the provider per email with routing rules, failover, weighted load balancing and per-provider rate limits.

```
your app ── POST /emails ──▶ mailroute ──▶ route rules ──▶ SMTP A / SMTP B / Resend / SendGrid / Mailgun
                                         (failover, weights, quotas, circuit breaker)
```

## Features

- **Resend-compatible API**: `POST /emails`, `POST /emails/batch`, `GET /emails`, `GET /emails/{id}`, `PATCH /emails/{id}`, `POST /emails/{id}/cancel`. Same payloads and error format, so the Resend SDKs work by changing the base URL. Also served under `/v1`.
- **Upstreams**: any SMTP server (STARTTLS, SSL or plain), Resend, SendGrid, Mailgun (US/EU).
- **Routing rules** matched top to bottom on sender domain, recipient domain (wildcards supported), tag, or API key, with a default route as fallback. Force a route per request with the `X-Route` header.
- **Strategies**: priority + failover, or weighted round-robin with the remaining providers as fallbacks.
- **Rate limits** per provider per minute / hour / day. When a limit is reached, mail moves to the next provider.
- **Circuit breaker**: after N failures in a row a provider is skipped for a cooldown period.
- **Smart retries**: connection or auth problems fail over to the next provider; rejected recipients (550-553) stop immediately; if every provider fails, the email is retried after 1 min, 5 min and 30 min.
- Scheduling (`scheduled_at`, ISO date or "in 1 hour"), `Idempotency-Key`, attachments (base64 or URL, 40MB), tags, custom headers.
- **Dashboard** with a sidebar layout, light and dark mode:
  - Overview with animated charts: daily volume (7/14/30 days), status donut, provider share, top recipient domains, success rate and average delivery time.
  - Send log with status tabs, search and filters; a side drawer to read any email (sandboxed HTML preview, plain text, source, attachments, attempts); `.eml` download, duplicate, retry, bulk retry of failed mail and CSV export.
  - Compose and schedule emails from the browser through the same routing as the API.
  - Providers with health and quota usage, test connection and send test; route editor with a simulator; API keys.
- **Users and dynamic roles**: add, edit, deactivate and remove users; create roles and tick exactly which of 12 permissions they get (for example, a role that sees the send log but not email content). The built-in Owner role always has everything and the last active owner cannot be removed.
- **Login protection**: 5 wrong passwords for an account from one IP (or 20 from one IP overall) lock sign-in for 30 minutes (`MAILROUTE_LOGIN_MAX_ATTEMPTS`, `MAILROUTE_LOGIN_LOCKOUT_MINUTES`).

## Stack

Laravel 13, Livewire 4, Tailwind CSS 4, PostgreSQL. No Redis: the queue, cache and rate limiter use the database (`SELECT … FOR UPDATE SKIP LOCKED` keeps workers from picking the same job).

## Setup

Requirements: PHP 8.3+ with `pdo_pgsql`, Composer, Node 20+, PostgreSQL 13+.

```bash
git clone https://github.com/Adytm404/http-email.git mailroute && cd mailroute
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
# edit .env: APP_URL and DB_* for your PostgreSQL database
php artisan migrate
```

Run it locally:

```bash
php artisan serve
```

```bash
php artisan queue:work --queue=emails,default
```

Open `http://localhost:8000`. On first visit you create the admin account (or run `php artisan app:create-admin you@example.com`). Then add a provider, optionally a default route, and an API key.

## Sending

```bash
curl -X POST http://localhost:8000/emails \
  -H "Authorization: Bearer re_your_key" \
  -H "Content-Type: application/json" \
  -d '{"from":"Acme <hello@acme.com>","to":"user@example.com","subject":"Hello","html":"<p>It works</p>"}'
```

With the Resend Node SDK, set `RESEND_BASE_URL=https://your-mailroute-host` and keep your code as it is.

Extra response fields beyond Resend's schema on `GET /emails/{id}`: `status`, `provider`, `provider_message_id`, `attempts`, `sent_at`, `failed_reason`.

## How a send is routed

1. If `X-Route` names an active route, it is used.
2. Otherwise the first active route (in the order shown on the Routing page) whose conditions all match wins; if none match, the default route is used; with no routes at all, every active provider is used by priority.
3. Providers that are paused or cooling down are dropped. Failover orders the rest by priority; weighted draws the first provider by weight and keeps the others as fallbacks.
4. Each candidate must reserve a slot in its minute/hour/day limits (an atomic Postgres upsert), otherwise it is skipped.

## Production

Run the queue worker and the scheduler under a process manager. Example systemd units:

```ini
# /etc/systemd/system/mailroute-worker@.service  (enable mailroute-worker@1 … @4)
[Service]
User=www-data
WorkingDirectory=/var/www/mailroute
ExecStart=/usr/bin/php artisan queue:work --queue=emails,default --sleep=1 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```cron
* * * * * cd /var/www/mailroute && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `emails:prune` daily (retention is set on the Settings page). Serve `public/` with Nginx + PHP-FPM as for any Laravel app, and run `php artisan config:cache route:cache view:cache` on deploy.

## Tests

Tests run against PostgreSQL in a separate `testing` schema of the database in `.env` (create it once with `CREATE SCHEMA testing;`):

```bash
php artisan test
```
