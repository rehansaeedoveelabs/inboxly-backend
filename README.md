# Inboxly Backend

Laravel REST API for Inboxly, a workspace-based Shopify marketing SaaS.

## Stack

- PHP **8.4+**
- Laravel **12**
- MySQL **8+**
- Laravel Sanctum (first-party SPA cookie authentication)
- API prefix: `/api/v1`

Frontend (separate repository): Vite + React at `http://localhost:5173`.

## Requirements

- PHP 8.4 with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`
- Composer 2
- MySQL listening on `127.0.0.1:3306`
- Dedicated databases only:
  - Application: `inboxly`
  - Automated tests: `inboxly_testing`
- Never point this app at Nexora, DataSyncAI, recovery dumps, or other shared databases

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
```

Create MySQL databases (phpMyAdmin or CLI):

```sql
CREATE DATABASE inboxly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE inboxly_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set local credentials in `.env` (never commit `.env`):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inboxly
DB_USERNAME=root
DB_PASSWORD=your-local-password

FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

Run migrations against the application database only:

```bash
php artisan migrate
```

Do **not** run `migrate:fresh` or `db:wipe` against shared or production data.

## Development server

```bash
php artisan serve
```

Default local API base: `http://localhost:8000`  
Health check: `GET /up`

## SPA authentication flow (React)

Sanctum cookie auth — **do not store API tokens in localStorage**.

1. `GET /sanctum/csrf-cookie` with `credentials: 'include'`
2. `POST /api/v1/auth/login` or `/api/v1/auth/register` with `credentials: 'include'` and `X-XSRF-TOKEN`
3. Call authenticated `/api/v1/*` endpoints with `credentials: 'include'`
4. `POST /api/v1/auth/logout` with credentials

Required browser/client settings:

- Exact CORS origin match (`FRONTEND_URL` / `CORS_ALLOWED_ORIGINS`)
- Credentials enabled
- CSRF cookie + header for mutating requests

Local HTTP cookie defaults:

- `SESSION_SECURE_COOKIE=false`
- `SESSION_SAME_SITE=lax`
- `SESSION_HTTP_ONLY=true`

Production HTTPS should set `SESSION_SECURE_COOKIE=true` and configure real domains.

## Mail

Development default: `MAIL_MAILER=log` (writes to `storage/logs`).

Password reset and email verification use Laravel’s mail system. Configure SMTP later via:

- `MAIL_MAILER=smtp`
- `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD`
- `MAIL_SCHEME` (for example `tls`)
- `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`

## Queues

Default: `QUEUE_CONNECTION=database` (uses `jobs` / `failed_jobs` tables).

Optional worker (start only when needed):

```bash
php artisan queue:work --tries=3
```

## Cache and sessions

Local defaults:

- `CACHE_STORE=database`
- `SESSION_DRIVER=database`
- `SESSION_LIFETIME=120`

Redis is optional and not required for local development.

## File storage

- Default disk: `local` → `storage/app/private` (not publicly served)
- Public assets (if needed later): `public` disk + `php artisan storage:link`
- Production can switch to S3-compatible storage (`AWS_*` / `AWS_ENDPOINT` for R2)

## Tests

PHPUnit is **forced** to MySQL database `inboxly_testing` in `phpunit.xml` (`force="true"`), so `.env` cannot accidentally target `inboxly`.

```bash
php artisan test
```

## Useful commands

```bash
php artisan about
php artisan route:list --path=api/v1
php artisan config:clear
php artisan migrate:status
php artisan test
```

## Production checklist (deployment-dependent)

- Set `APP_ENV=production` and `APP_DEBUG=false`
- Use a strong unique `APP_KEY` (do not reuse local keys)
- Configure production `APP_URL`, `FRONTEND_URL`, Sanctum stateful domains, and CORS origins
- Enable `SESSION_SECURE_COOKIE=true` behind HTTPS
- Configure real SMTP
- Choose production queue/cache drivers
- Configure S3/R2 if private object storage is required
- Never commit `.env` or credentials
