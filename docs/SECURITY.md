# Security

Security behavior as implemented in this repository. This is not a penetration-test report.

## Sanctum SPA authentication

- Guard: session `web` + `auth:sanctum` on protected API routes (`routes/api.php`).
- SPA flow: CSRF cookie → login/register → session cookie on subsequent `/api/v1/*` calls.
- Controllers: `SessionController`, `RegisterController`, `ProfileController`.
- Personal access tokens: table/migration exists; **SPA does not use bearer tokens**.

### CSRF and sessions

- `config/sanctum.php` stateful domains from `SANCTUM_STATEFUL_DOMAINS`.
- `config/cors.php`: exact origins, `supports_credentials` true, paths `api/*` and `sanctum/csrf-cookie`.
- Sessions: database driver; `http_only` true; local example uses `SESSION_SECURE_COOKIE=false` and `SESSION_SAME_SITE=lax`.
- Production must use HTTPS + `SESSION_SECURE_COOKIE=true` and real domains.

## Authorization and policies

| Policy | Gate summary |
|--------|----------------|
| `WorkspacePolicy` | Members view; Admins update/settings/members/invites/createStore |
| `StorePolicy` | Members view/activate/`manageAudience`; Admins Shopify connect/disconnect |
| `ContactPolicy`, `TagPolicy`, `SegmentPolicy`, `ContactImportPolicy`, `ContactExportPolicy` | Delegate to store `manageAudience` |

FormRequests often call the same gates in `authorize()`.

## Workspace / store isolation

- Child resources authorize through owning store’s workspace membership.
- Foreign tag IDs fail validation or 404 on detach.
- `SegmentQueryBuilder` always ANDs `store_id` outside OR condition groups.
- `SetActiveStore` verifies store belongs to the workspace.

There is **no** per-store ACL beyond workspace membership today (`StorePolicy::activate` notes future restrictions).

## Shopify credential protection

- Tokens cast as `encrypted`; listed in `$hidden`; excluded from `$fillable`.
- Written only via model helpers (`storeShopifyCredentials`, rotate/invalidate/disconnect/clear).
- `StoreResource` exposes connection metadata, **not** tokens.
- OAuth: constant-time HMAC checks, single-use state under row lock, strict `*.myshopify.com` validation (`ShopifyDomain`), redirects via `FrontendUrl` only.

## Webhook verification

- `HandleShopifyWebhook` verifies HMAC over the **raw** body.
- Delivery idempotency via unique `webhook_id` in `shopify_webhook_deliveries`.
- Registration URI validated (`ShopifyWebhookUri`) — HTTPS, blocks localhost/private unless explicitly allowed.

## Private export files

- Files on private `local` disk (`exports/{storeId}/{exportId}.csv`).
- `path` / `disk` not mass-assignable on `ContactExport` and omitted from `ContactExportResource`.
- Download requires auth + `ContactExportPolicy::download` + completed + unexpired + file present.
- Create/download throttled (`throttle:contact-exports`, 10/min per user).

### Status-transition safety

Export completion, failure, and stale prune use **conditional SQL updates** so a completed export cannot be overwritten to failed (and vice versa) without winning the claim; file deletion runs only after a successful fail/stale claim (or after a lost completion race for the worker’s own file).

## CSV formula injection protection

`ProcessContactExportJob::cell` prefixes a leading `'` when values start with `=`, `+`, `-`, `@`, tab, or carriage return.

## Rate limiting

Defined in `AppServiceProvider`:

- `auth` — 10/min per email+IP
- `password-reset` — 5/min per email+IP
- `contact-exports` — 10/min per user (fallback IP)

Inline throttles also protect Shopify OAuth start/callback/webhooks and email verification resend. There is **no** global authenticated API throttle.

## Known gaps and production requirements

| Topic | Current state | Production expectation |
|-------|---------------|------------------------|
| Email verification | Implemented links; **no** `verified` middleware on API | Enforce before sensitive actions if required |
| Invitation accept | Email sent; **no** accept API | Implement accept + membership write |
| Token refresh | `ShopifyCredentialService` unused by app paths | Wire refresh before calling Shopify Admin APIs |
| Session fixation across devices | Sanctum `AuthenticateSession` not applied on API | Consider enabling for password changes |
| Marketer PII export | Marketers can export contacts | Confirm product policy / add finer roles |
| Secrets | `.env` gitignored; `.env.example` only | Rotate keys; never commit secrets |
| Ops | Queue worker + scheduler required | Supervisord/systemd + cron `schedule:run` |
| Transport | Local HTTP cookies OK for dev | TLS everywhere in production |

## Related docs

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [APPLICATION-FLOWS.md](APPLICATION-FLOWS.md)
- [ROADMAP-AND-GAPS.md](ROADMAP-AND-GAPS.md)
