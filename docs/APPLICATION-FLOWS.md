# Application flows

Step-by-step flows as implemented in this backend. Frontend steps describe the companion SPA when it is wired; see the frontend docs for UI gaps.

## 1. Registration and login

**Routes:** `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`
**Code:** `RegisterController`, `Actions\RegisterUser`, `SessionController`, `ProfileController`

1. Client obtains CSRF cookie: `GET /sanctum/csrf-cookie`.
2. **Register:** validates via `RegisterRequest` → creates `User` + default `Workspace` (Admin membership + `UserWorkspaceContext`) in a transaction → fires `Registered` (verification email) → logs in on `web` guard → returns `UserResource` (201).
3. **Login:** `Auth::guard('web')->attempt` → session regenerate → `UserResource`.
4. **Me:** returns the authenticated user (includes workspaces for the SPA).
5. **Logout:** logout, invalidate session, regenerate CSRF token (204).

Password reset and email verification endpoints exist (`PasswordResetController`, `EmailVerificationController`). Verification is **not** required by middleware on API routes today.

## 2. Workspace creation and membership

**Routes:** `POST/GET/PUT /api/v1/workspaces`, `GET .../members`, `POST .../invitations`
**Code:** `WorkspaceController`, `CreateWorkspace`, `InviteMember`, `WorkspaceInvitationNotification`

1. Authenticated user creates a workspace → owner Admin membership + context row.
2. Admins list members via `WorkspaceMemberController`.
3. Admins invite by email → `InviteMember` stores **hashed** token, emails `FRONTEND_URL/accept-invitation?...`.

**Gap:** There is **no** accept-invitation API action. `accepted_at` is never set by this codebase; invited users are not added as members through an HTTP flow.

## 3. Store creation and activation

**Routes:** `POST/GET /api/v1/workspaces/{workspace}/stores`, `POST /api/v1/stores/{store}/activate`
**Code:** `StoreController`, `CreateStore`, `StoreActivationController`, `SetActiveStore`

1. Admin creates a store:
   - Real: normalized `*.myshopify.com` domain, status `pending`
   - Demo: `is_demo`, status `demo`, no credentials (`Store::booted` invariants)
2. Any member activates a store for a workspace → upserts `user_workspace_contexts.active_store_id` after membership + ownership checks.

No HTTP update/delete store routes; soft-delete behavior exists on the model for when deletion is invoked internally.

## 4. Shopify OAuth and webhooks

**Routes:**
- `GET /api/v1/stores/{store}/shopify/oauth/start`
- `GET /api/v1/shopify/oauth/callback` (public)
- `DELETE /api/v1/stores/{store}/shopify/connection`
- `POST /api/v1/shopify/webhooks` (public)

**Code:** `ShopifyOAuthController`, `StartShopifyOAuth`, `HandleShopifyOAuthCallback`, `DisconnectShopifyStore`, `HandleShopifyWebhook`, `RegisterShopifyWebhooksJob`

### Connect / reconnect

1. Admin hits start → validates store eligibility, member context, domain uniqueness → writes `ShopifyOauthState` → 302 to Shopify authorize URL for the **persisted** shop domain.
2. Shopify redirects to callback with code + state.
3. Callback: HMAC + timestamp window + consume state under lock → token exchange → GraphQL shop identity → persist encrypted credentials + `connected` → dispatch webhook registration → redirect to frontend `/settings/storefront?...`.

### Disconnect

Admin DELETE connection → best-effort remote revoke → clear local credentials (identity may remain for reconnect).

### Webhooks

1. Verify `X-Shopify-Hmac-Sha256` over raw body.
2. Insert `shopify_webhook_deliveries` by unique `webhook_id` (duplicate → 200 no-op).
3. `app/uninstalled` → clear credentials / disconnected; `app/scopes_update` → update scopes.

**Gap:** `ShopifyCredentialService` refresh helpers are not called from controllers/jobs/scheduler.

## 5. Contact management

**Routes:** under `stores/{store}/contacts` and `contacts/{contact}`
**Code:** `ContactController`, `UpsertContact`, `UpdateContact`, `ContactListQuery`, `ContactPolicy`

1. **List:** cursor pagination (`created_at DESC`, `id DESC`) with filters `q` (email prefix), `tag`, `consent_email` via shared `ContactListQuery`.
2. **Create:** requires email or phone; creates three `nonsubscribed` consent rows. Soft-deleted identity clash → **409** `contact_deleted` unless `restore=true`.
3. **Update / soft-delete / show:** policy via owning store.
4. **Tags:** attach/detach with store ownership checks (`ContactTagController`, `AttachTags`).
5. **Consents:** `PUT .../consents` → `UpdateContactConsents` (transactional; appends `ConsentEvent` only on change).

## 6. Contact imports

**Routes:** `POST /api/v1/stores/{store}/contact-imports`, `GET /api/v1/contact-imports/{id}`
**Code:** `ContactImportController`, `StartContactImport`, `ProcessContactImportJob`

1. Client posts up to 1000 JSON rows (+ optional tag names/filename) — **not** a multipart file upload.
2. Action creates/finds tags, stores rows in `contact_imports.options.rows`, status `pending`, returns **202**, dispatches job.
3. Job processes chunks of 100 in transactions via `UpsertContact` (restores soft-deleted matches and sets review gate), attaches tags, updates counters including `restored_count`.
4. On success, removes `options.rows`. Failures keep error summaries; `tries = 1`.

No import list/cancel endpoints.

## 7. Contact restoration and consent review

**Routes:** `POST /api/v1/contacts/{contact}/restore` (withTrashed), `POST /api/v1/contacts/{contact}/consent-review`
**Code:** `RestoreContact`, `ReviewContactConsents`

1. **Restore:** locks row, restores soft-deleted contact, sets `consent_review_required_at`, **does not** change consent statuses or rewrite events.
2. **Consent review:** clears `consent_review_required_at` only (no status/event mutation).
3. `Contact::isMarketingEligible()` returns false while trashed or review-required — reserved for future sending (unused by campaigns today).

## 8. Segment creation and preview

**Routes:** CRUD under `stores/{store}/segments` / `segments/{segment}`, plus `POST .../preview`
**Code:** `SegmentController`, `ValidSegmentRules`, `SegmentRules`, `SegmentQueryBuilder`

1. Create/update validates rule schema (operator, ≤20 conditions, allowed fields/ops).
2. Membership is computed at read/preview time — no segment_members table.
3. Preview returns live `count` and a sample of up to 10 contacts.
4. Store scope is always applied outside OR groups.

Segments do not apply the consent-review / marketing-eligibility gate.

## 9. Contact export, processing, and download

**Routes:**
- `POST/GET /api/v1/stores/{store}/contact-exports`
- `GET /api/v1/contact-exports/{id}`
- `GET /api/v1/contact-exports/{id}/download`

**Code:** `StartContactExport`, `ProcessContactExportJob`, `ContactExportController`, `PruneContactExportsCommand`

1. Start with optional filters matching list semantics → create `pending` export → dispatch job → **202**.
2. Job atomically claims `pending → processing`, opens CSV with exclusive create (`fopen(..., 'xb')`), streams keyset batches through `ContactListQuery`, escapes formula-like cells.
3. Completes with atomic `processing → completed` (counters, `expires_at` ≈ 7 days, `finished_at`). Lost races delete the worker’s file and leave the DB winner intact.
4. Failures claim `pending|processing → failed` **before** deleting any file.
5. Download: policy + completed + not expired + file exists → stream `text/csv` from private disk (`path`/`disk` not exposed in `ContactExportResource`).
6. Daily prune: stale `processing` (>900s) conditional fail + file delete; then delete expired rows/files.

**Frontend note:** the SPA export page is still a placeholder; the API above is live.

## Related docs

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [DATABASE.md](DATABASE.md)
- [SECURITY.md](SECURITY.md)
