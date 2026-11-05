# mailroute

Self-hosted email router: Resend-compatible HTTP API in front of many SMTP/HTTP providers. See README.md for features.

## Layout
- `app/Http/Controllers/Api/EmailController.php` + `app/Services/EmailIntake.php`: public API (validation, idempotency, attachments, queueing). Errors use Resend's shape via `app/Http/ApiError.php` and the renderer in `bootstrap/app.php`.
- `app/Jobs/SendEmailJob.php`: walks the candidate providers, records `email_attempts`, retries with backoff.
- `app/Routing/`: `EmailRouter` (rule matching, failover/weighted ordering), `QuotaManager` (atomic Postgres upsert per minute/hour/day), `CircuitBreaker`.
- `app/Mail/Drivers/`: one driver per provider type; `DriverException` classifies failures as retryable / provider fault / permanent.
- `app/Livewire/` + `resources/views/livewire/`: dashboard. Design tokens in `resources/css/app.css`, shared UI in `resources/views/components/ui/`.

## Conventions
- PostgreSQL only (jsonb, `ON CONFLICT`, `ilike`). No Redis: queue, cache and rate limiter use the database.
- API routes have no `/api` prefix (they mirror api.resend.com); the dashboard lives under `/dashboard`.
- Tests use the `testing` schema (`DB_SEARCH_PATH` in phpunit.xml) and `Tests\Support\FakeDriverFactory` instead of real providers.
- Commit and push to GitHub after each completed change.
