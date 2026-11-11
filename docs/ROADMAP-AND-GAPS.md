# Roadmap and gaps

Honest inventory of what this backend implements versus what is incomplete or only prepared for the future. Frontend mock/stub behavior is summarized here for integration context; details live in the frontend docs.

## Implemented (backend)

| Area | Evidence |
|------|----------|
| Sanctum SPA auth | `routes/api.php` auth group; session login/register/logout/me |
| Workspaces | CRUD-ish endpoints; Admin/Marketer roles |
| Stores | Create, list, activate; connection status enum |
| Shopify OAuth lifecycle | Start, callback, disconnect, reconnect path in callback |
| Shopify webhooks | Uninstall + scopes update; delivery idempotency |
| Contacts / tags / consents | Full store-scoped APIs + soft delete |
| Restore + consent review | `RestoreContact`, `ReviewContactConsents` |
| Segments | Rules validation, query builder, preview |
| Imports | Queued JSON-row import job |
| Exports | Queued CSV, atomic claims, download, prune command |
| Automated tests | Broad Feature/Unit coverage under `tests/` |

## Incomplete (backend)

| Gap | Detail |
|-----|--------|
| Invitation acceptance | Invite creates hashed token + email; **no** accept endpoint/action |
| Member management | No remove member / change role / revoke invite HTTP APIs |
| Store update/delete HTTP | Soft-delete hooks exist; no public update/destroy routes |
| Workspace delete | Soft deletes on model; no destroy route |
| Email verification enforcement | `MustVerifyEmail` present; routes lack `verified` middleware |
| Shopify token refresh wiring | `ShopifyCredentialService` exists; unused by controllers/jobs |
| Webhook job vs refresh | Registration job uses stored access token directly |
| Import file upload | Inline JSON rows only (≤1000); PII held in `options.rows` until success |
| Import list/cancel | Show-by-id only |
| Export ↔ segments | Exports filter like contact list; cannot target a segment id |
| Consent event read API | Events written; no list endpoint |
| `isMarketingEligible` usage | Helper exists; no campaigns/sends call it |
| Personal access tokens | Migration present; SPA unused |

## Frontend integration gaps (companion repo)

| Backend capability | Frontend status (as of audit) |
|--------------------|-------------------------------|
| Contact exports API | **Stub** page (“coming later”) |
| Invitation create API | No members/invites UI; accept route missing on both sides |
| Password reset / email verified redirects | Backend redirects; SPA routes missing |
| Profile/password APIs | Account settings page is mock/local |
| Import list API | SPA uses `sessionStorage` history |

## Mock / stub on frontend (not backend features)

Campaigns, automation, forms, analytics/live, image library, brand assets, channel settings, and most of dashboard metrics are **UI mocks** in `inboxly-frontend`. They must not be documented as live product capabilities.

## Planned / not present in backend code

- Campaigns, templates, journeys, email/SMS/push sending
- Shopify customer/order/product sync
- Non-Shopify store platforms (`StorePlatform` is Shopify-only)
- Required Redis/Horizon
- Required S3/R2 for exports (disk config exists; export job hardcodes `local`)

## Production-readiness gaps

1. Run **queue workers** and **scheduler** in every non-sync environment.
2. Wire **Shopify credential refresh** before relying on long-lived Admin API calls.
3. Finish **invitation accept** before treating multi-user workspaces as complete.
4. Decide and enforce **email verification** policy.
5. Configure HTTPS session cookies, real CORS/Sanctum domains, SMTP, monitoring.
6. Treat marketing modules as **unbuilt** until APIs and real UI exist.
7. Keep frontend export UI in sync with the export API before advertising CSV export to end users.

## Related docs

- [ARCHITECTURE.md](ARCHITECTURE.md)
- [SECURITY.md](SECURITY.md)
- Frontend: `docs/ROADMAP-AND-GAPS.md` in [inboxly-frontend](https://github.com/rehansaeedoveelabs/inboxly-frontend)
