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
| Campaigns (email) | Draft/schedule/send/test-send pipeline; Meta publish support; SMS via `fake` or Vonage |
| Welcome Automation | `contact.email_subscribed` trigger, linear delay + send_email steps, enrollments/runs, activate/pause/archive, `automations:dispatch-due` scheduler; local sends use the **fake** email driver |
| Shopify commerce sync (thin slice) | Admin GraphQL backfill: customer GIDs → `shopify_customer_identities`; products/orders tables; `shopify_sync_runs` + `POST/GET .../shopify/sync` |

## Incomplete (backend)

| Gap | Detail |
|-----|--------|
| Invitation acceptance | Invite creates hashed token + email; **no** accept endpoint/action |
| Member management | No remove member / change role / revoke invite HTTP APIs |
| Store update/delete HTTP | Soft-delete hooks exist; no public update/destroy routes |
| Workspace delete | Soft deletes on model; no destroy route |
| Email verification enforcement | Onboarding uses email OTP before Shopify install; signed-link routes remain; not all API routes require `verified` |
| Shopify token refresh wiring | `ShopifyCredentialService` refreshes for webhook registration and credential helpers; all future Admin customer APIs must use it |
| Shopify privacy compliance | M1 handlers/Admin APIs/lease recovery/ops report in code; Partner/TOML subscription + staging activation **not** done; external alerting still required; see [PRIVACY-DATA-INVENTORY.md](PRIVACY-DATA-INVENTORY.md) and [M1-COMPLIANCE-OPERATIONS.md](M1-COMPLIANCE-OPERATIONS.md) |
| Import file upload | Inline JSON rows only (≤1000); PII held in `options.rows` until success (cleared on success; retained on failure) |
| Import list/cancel | Show-by-id only |
| Export ↔ segments | Exports filter like contact list; cannot target a segment id |
| Consent event read API | Events written; no list endpoint |
| SMS campaign sending | Fake + Vonage drivers shipped; real sends need `MESSAGING_SMS_DRIVER=vonage` + `VONAGE_SMS_SENDING_ENABLED=true` |
| Personal access tokens | Migration present; SPA unused |

## Frontend integration gaps (companion repo)

| Backend capability | Frontend status (as of audit) |
|--------------------|-------------------------------|
| Contact exports API | **Stub** page (“coming later”) |
| Invitation create API | No members/invites UI; accept route missing on both sides |
| Password reset / email verified redirects | Backend redirects; SPA routes missing |
| Profile/password APIs | **Wired** — Account settings uses profile/password + workspace members |
| Import list API | SPA uses `sessionStorage` history |

## Mock / stub on frontend (not backend features)

Revenue/open-rate charts are not available (no attribution pipeline yet).

**Wired:** Forms (CRUD + public embed/submit + template gallery + Omnisend-style studio), image library, brand assets (`stores/{store}/brand`), channel settings (`stores/{store}/channels`), Analytics + Live activity, Account settings, Dashboard metrics. Campaigns (email/SMS) and Welcome Automation use `campaignsApi.js` / `automationsApi.js`. Local/dev messaging defaults to fake drivers unless Brevo/Vonage are enabled.

## Deferred automation / marketing features (partially shipped)

- Visual automation canvas / graph editor — **still deferred**
- Simple linear condition branch (`condition` step) + `contact.tag_added` trigger — **shipped** (thin slice; not a canvas)
- SMS campaign send via `MESSAGING_SMS_DRIVER=fake` — **shipped** locally; real Vonage via `MESSAGING_SMS_DRIVER=vonage` + `VONAGE_SMS_SENDING_ENABLED=true`
- Live store insights overview + activity (`GET .../insights/overview|activity`) — **shipped** (no revenue analytics yet)
- Public forms (`/api/v1/public/forms/{token}`) → contact upsert + consent — **shipped**
- Brand assets on `store.metadata.brand` — **shipped**
- Shopify contact write-back on Inboxly contact edit (`write_customers` + linked identity) — **shipped**
- Real Brevo delivery — available via env (`MESSAGING_EMAIL_DRIVER=brevo` + `BREVO_SENDING_ENABLED=true`); defaults remain fake

## Planned / not present in backend code

- Shopify customer identity schema (M2) + commerce sync backfill (11A.3) exist and materialize Contacts from Shopify customers; conflict/link Admin workflows and Customer Events / commerce webhooks remain unimplemented (write-back updates already-linked customers only)
- Staging/production activation of Shopify compliance webhook subscriptions (M1 code exists; activation gated)
- Non-Shopify store platforms (`StorePlatform` is Shopify-only)
- Required Redis/Horizon
- Required S3/R2 for exports (disk config exists; export job hardcodes `local`)

## Production-readiness gaps

1. Run **queue workers** and **scheduler** in every non-local environment (scheduler includes M1 compliance recover every 5 minutes).
2. Keep **Shopify credential refresh** in front of every Admin API call (including future customer sync).
3. Finish **invitation accept** before treating multi-user workspaces as complete.
4. Decide and enforce **email verification** policy.
5. Configure HTTPS session cookies, real CORS/Sanctum domains, SMTP, monitoring.
6. Complete **privacy/compliance** readiness (inventory is M0; handlers are M1+) before production Shopify customer-data synchronization.
7. Keep frontend export UI in sync with the export API before advertising CSV export to end users.
8. Document **backup erase/expiry** for privacy requests (not in-repo today).

## Related docs

- [M1-COMPLIANCE-OPERATIONS.md](M1-COMPLIANCE-OPERATIONS.md)

- [PRIVACY-DATA-INVENTORY.md](PRIVACY-DATA-INVENTORY.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [SECURITY.md](SECURITY.md)
- Frontend: `docs/ROADMAP-AND-GAPS.md` in [inboxly-frontend](https://github.com/rehansaeedoveelabs/inboxly-frontend)
