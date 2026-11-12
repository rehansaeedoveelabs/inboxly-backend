# Privacy data inventory (Phase 11A.2.0 / M0)

Documentation-only milestone. This inventory is grounded in the current `inboxly-backend` and `inboxly-frontend` repositories. It does **not** authorize storing synchronized Shopify customer PII.

**Label key**

| Label | Meaning |
|-------|---------|
| **Verified** | Confirmed from schema, model, job, or config in this repo |
| **Inferred** | Reasonable risk from framework defaults or adjacent code; not proven in production ops |
| **Recommendation** | Future control; not implemented |
| **External** | Legal, Partner Dashboard, backups, or hosting — not verifiable from this repo |

**Architectural constraints (approved)**

- Shopify customer identities will live separately from Inboxly `contacts` (not implemented yet).
- Soft delete is **not** privacy erasure.
- Shopify customer deletion ≠ Inboxly contact deletion and must not change Inboxly consent.
- Linked Inboxly contact erasure after `customers/redact`, and post-redaction GID retention, remain **unresolved legal decisions**.
- Customer PII synchronization must not begin until privacy/compliance gates are satisfied.

---

## 1. Inventory by location

### 1.1 Contacts (including soft-deleted)

| Item | Detail |
|------|--------|
| Location | Table `contacts`; model `App\Models\Contact` |
| Personal data | `email`, `phone`, `first_name`, `last_name`, `locale`, `timezone`, `source`, `metadata` (JSON), timestamps, `deleted_at` |
| Source | Manual API, CSV import (`UpsertContact`), future Shopify sync (**not implemented**) |
| Tenant | `store_id` → `stores` |
| Retention | **Not defined** in code beyond soft-delete retention |
| Deletion | HTTP destroy soft-deletes (`ContactController::destroy` → `$contact->delete()`). Soft-deleted rows **retain PII** and still occupy unique `(store_id, email\|phone)`. |
| Cascade | Hard-delete of contact cascades to consents, consent_events, tags pivot, campaign_recipients (`cascadeOnDelete` on those FKs). Soft-delete does **not**. |
| Export/erasure API | No dedicated privacy export/erasure. Marketer/Admin can soft-delete; CSV export exists for active contacts only (see exports). |
| Future handling | Include soft-deleted contacts in privacy inventory searches; distinguish soft-delete from redact/erase. |
| Evidence | `database/migrations/2026_11_10_160000_create_contacts_table.php`; `app/Models/Contact.php` (`SoftDeletes`); `app/Http/Controllers/Api/V1/ContactController.php` |
| Status | **Verified** |

### 1.2 Contact consents

| Item | Detail |
|------|--------|
| Location | `contact_consents`; `App\Models\ContactConsent` |
| Personal data | Per-channel marketing status (`email` / `sms` / `push`), `source`, `consented_at` — marketing preference data linked to a person via `contact_id` |
| Source | Created as `nonsubscribed` on contact create (`UpsertContact`); updated via consent APIs |
| Tenant | Via `contact_id` → contact → store |
| Retention | None coded |
| Deletion | Hard-deleted when contact is **hard**-deleted (`cascadeOnDelete`). Survives soft-delete. |
| Export/erasure | No privacy-specific mechanism |
| Future | Inventory for data_request; erasure policy for redact is a **legal decision** |
| Evidence | `database/migrations/2026_11_10_160100_create_contact_consents_table.php`; `UpsertContact` |
| Status | **Verified** |

### 1.3 Consent events (append-only application semantics)

| Item | Detail |
|------|--------|
| Location | `consent_events`; `App\Models\ConsentEvent` |
| Personal data | Channel, status, source, optional `meta` JSON, `contact_id`, `created_at` |
| Source | Consent status changes |
| Tenant | Via contact → store |
| Retention | None coded |
| Deletion | Eloquent blocks `updating`/`deleting` (`LogicException`). Database FK is `cascadeOnDelete` on `contact_id`, so **hard-deleting a contact still removes events at DB level**, bypassing Eloquent guards. Soft-delete of contact leaves events intact. |
| Export/erasure | No list/export API (**Verified** gap in ROADMAP). No privacy erase workflow. |
| Future | Treat as sensitive audit history; do not assume soft-delete or “append-only” equals legal retention. Redact policy is **External legal**. |
| Evidence | `ConsentEvent::booted`; migration `2026_11_10_160100_*` |
| Status | **Verified** |

### 1.4 Suppressions

| Item | Detail |
|------|--------|
| Location | `suppressions`; `App\Models\Suppression` |
| Personal data | `channel`, `address` (email/phone-like), `reason`, `source` |
| Source | Sending / bounce / unsubscribe paths (campaign stack) |
| Tenant | `store_id` |
| Retention | None coded |
| Deletion | Cascades on **store hard-delete**; no contact FK (address-based). Soft-deleting a contact does **not** remove matching suppressions. |
| Export/erasure | None |
| Future | Include in customer/address-scoped inventory and redact design |
| Evidence | `database/migrations/2026_11_11_230200_create_suppressions_table.php` |
| Status | **Verified** |

### 1.5 Campaigns and campaign recipients

| Item | Detail |
|------|--------|
| Location | `campaigns` (SoftDeletes); `campaign_recipients` |
| Personal data | Recipients: `address`, `contact_id`, provider message ids, error strings. Campaigns may embed personal content in HTML/social fields. Meta publications store `message_snapshot` / `link_snapshot`. |
| Source | Audience resolution + send pipeline |
| Tenant | Campaign → `store_id`; recipients → campaign |
| Retention | None coded for recipients |
| Deletion | Recipient rows cascade when campaign or contact is **hard**-deleted. Campaign soft-delete retains recipient rows. |
| Export/erasure | None for privacy |
| Future | Include recipient addresses in data_request/redact inventories; content snapshots may contain PII (**Inferred** if operators paste personal data) |
| Evidence | `2026_11_11_230100_create_campaign_recipients_table.php`; `2026_11_12_180000_phase_10b_meta_publishing.php`; `Campaign`, `CampaignRecipient`, `CampaignMetaPublication` |
| Status | **Verified** (fields); content-PII risk **Inferred** |

### 1.6 Contact imports and temporary `options.rows`

| Item | Detail |
|------|--------|
| Location | `contact_imports.options` JSON |
| Personal data | Temporary full contact rows under `options.rows` (email required per row, phone/names/metadata optional) |
| Source | API start import (frontend parses CSV then posts rows) |
| Tenant | `store_id` |
| Retention | On **successful** completion, job unsets `options.rows` (**Verified**). On **failure**, `markFailed` does **not** clear `rows` — PII can remain (**Verified**). |
| Deletion | Row cascades on store hard-delete; no dedicated PII purge for failed imports |
| API exposure | `ContactImportResource` documents that `options.rows` is never exposed |
| Frontend | `sessionStorage` key `inboxly.contactImportHistory.v1` stores import **metadata** from API (not raw rows). Browser also holds CSV text in component state while the modal is open. |
| Future | Clear or encrypt `options.rows` on failure; TTL prune for failed imports (**Recommendation**) |
| Evidence | `ProcessContactImportJob` (success unset; `markFailed`); `ContactImportResource`; `ImportsPage.jsx` |
| Status | **Verified** |

### 1.7 Contact exports and CSV files

| Item | Detail |
|------|--------|
| Location | DB `contact_exports`; files on private `local` disk at `exports/{storeId}/{exportId}.csv` |
| Personal data | CSV of active contacts (email, phone, names, consents/tags as written by job); DB holds filters, filenames, counters |
| Source | `ProcessContactExportJob` |
| Tenant | `store_id` |
| Retention | `ContactExport::EXPIRY_DAYS = 7`; `expires_at` set on completion/failure; `contact-exports:prune` deletes expired files/rows (**Verified** code constants — not a legal retention policy) |
| Deletion | Prune command; file delete after claim; store hard-delete cascades DB row (files may orphan if prune not run — **Inferred**) |
| Export/erasure | This **is** a product CSV export, not a GDPR data-request fulfillment workflow |
| Future | Include unexpired export files in redact/shop redact file sweep (**Recommendation**) |
| Evidence | `ContactExport.php`, `ProcessContactExportJob`, `PruneContactExportsCommand`, `docs/SECURITY.md` |
| Status | **Verified** |

### 1.8 Assets and email templates

| Item | Detail |
|------|--------|
| Location | `assets` (path on private disk, SoftDeletes); `email_templates` (`document` JSON, `html`, SoftDeletes) |
| Personal data | Not inherently customer PII. May **contain** personal data if uploaded/authored that way (**Inferred**). Asset metadata: filenames, uploader id. |
| Source | Store operators |
| Tenant | `store_id` |
| Retention | None beyond soft-delete |
| Deletion | Soft-delete on models; store hard-delete cascades DB; file cleanup behavior must be confirmed per destroy actions |
| Future | Usually out of Shopify customer redact scope unless content is customer-specific; include in shop-level content review if needed |
| Evidence | migrations `2026_11_11_220000_*`, `2026_11_11_220100_*` |
| Status | **Verified** structure; content-PII **Inferred** |

### 1.9 Stores — Shopify identity and encrypted credentials

| Item | Detail |
|------|--------|
| Location | `stores` columns: `shopify_shop_id`, `shopify_shop_domain`, encrypted `shopify_access_token` / `shopify_refresh_token`, expiry timestamps, `shopify_scopes`, `metadata` (e.g. webhook registration status) |
| Personal data | Shop domain/id are merchant identifiers; tokens are credentials (not end-customer PII but sensitive). No customer PII columns today. |
| Source | OAuth callback / refresh / scopes webhook / uninstall |
| Tenant | Store row; workspace ownership |
| Retention | Tokens cleared on uninstall webhook and disconnect helpers (**Verified**). Soft-delete of store clears Shopify identity/credentials in `Store::booted` (see DATABASE.md). |
| Deletion | Soft-delete ≠ cascade of child PII. Hard-delete cascades children. |
| Evidence | `Store` casts `encrypted`; `HandleShopifyWebhook::handleUninstalled`; migration `2026_11_10_170000_*` |
| Status | **Verified** |

### 1.10 Shopify OAuth states

| Item | Detail |
|------|--------|
| Location | `shopify_oauth_states` |
| Personal data | `shop_domain`, `user_id`, workspace/store ids; CSRF `state` |
| Retention | `expires_at`; pruned by `shopify:prune-oauth-states` (expired or consumed older than configurable hours, default 24) |
| Deletion | Hard prune; cascade on store/user delete |
| Evidence | migration `2026_11_10_180000_*`; `PruneShopifyOauthStatesCommand` |
| Status | **Verified** |

### 1.11 Shopify webhook deliveries

| Item | Detail |
|------|--------|
| Location | `shopify_webhook_deliveries` |
| Personal data | `webhook_id`, `topic`, `shop_domain`, optional `store_id` — **no payload body stored** (**Verified** fillable/migration) |
| Source | Classic HTTPS webhooks |
| Retention | None coded |
| Notes | Current topics: uninstall + scopes update only. Compliance/customer topics **not** handled. |
| Evidence | `ShopifyWebhookDelivery`, `HandleShopifyWebhook`, `config/services.php` `webhook_topics` |
| Status | **Verified** |

### 1.12 Brevo webhook events

| Item | Detail |
|------|--------|
| Location | `brevo_webhook_events` |
| Personal data | `email`, full JSON `payload`, provider message id, optional `campaign_recipient_id` |
| Source | Brevo webhooks |
| Tenant | Indirect via recipient → campaign → store |
| Retention | None coded |
| Deletion | Recipient FK `nullOnDelete`; no automatic purge |
| Future | Include email/payload in customer and shop inventories; minimize logged/stored payload (**Recommendation**) |
| Evidence | `2026_11_12_140100_create_brevo_webhook_events_table.php` |
| Status | **Verified** |

### 1.13 Meta integration

| Item | Detail |
|------|--------|
| Location | `meta_connections` (encrypted user token, `meta_user_id`), `meta_pages` (encrypted page token), `meta_oauth_states`, `campaign_meta_publications` |
| Personal data | Meta user id; tokens; publication message/link snapshots (may include personal content — **Inferred**) |
| Tenant | `store_id` |
| Relation to Shopify customers | None today |
| Evidence | Meta migrations + models with `encrypted` casts |
| Status | **Verified** (credentials); content-PII **Inferred** |

### 1.14 Users, memberships, invitations

| Item | Detail |
|------|--------|
| Location | `users` (name, email, password); `workspace_members`; `workspace_invitations` (invitee `email`, `token_hash`, `expires_at`); `sessions` (ip, user_agent, payload); `password_reset_tokens` |
| Source | Auth / workspace admin |
| Tenant | Workspace-scoped membership; users global |
| Retention | Invitation `expires_at` set; accept API incomplete (ROADMAP). Session retention follows session driver config. |
| Notes | Operator PII, not Shopify customer PII; still in application privacy scope for shop/account deletion |
| Evidence | `0001_01_01_000000_create_users_table.php`; `2026_11_10_150000_create_workspaces_table.php` |
| Status | **Verified** |

### 1.15 Database queues and failed jobs

| Item | Detail |
|------|--------|
| Location | `jobs.payload`, `failed_jobs.payload` / `exception` |
| Personal data | Jobs currently serialize **ids** (`contactImportId`, `contactExportId`, `storeId`, etc.), not full contact rows (**Verified** constructors). Failed job exception traces might echo PII if code logged it (**Inferred**). Import PII lives in DB `options.rows` while job runs, not necessarily in queue payload. |
| Retention | Until processed / failed_jobs pruned manually — **no app prune command verified** |
| Future | Avoid embedding row arrays in job payloads; prune `failed_jobs`; scrub exceptions (**Recommendation**) |
| Evidence | `0001_01_01_000002_create_jobs_table.php`; job constructors under `app/Jobs/` |
| Status | **Verified** (structure); residual PII in failures **Inferred** |

### 1.16 Application logs and exception reporting

| Item | Detail |
|------|--------|
| Location | Default `LOG_CHANNEL=stack` → `single` file under `storage/logs` (`.env.example`). No Sentry/Bugsnag config found in repo. |
| Personal data | Shopify handlers generally log shop_domain / store_id / reasons, not customer emails (**spot-checked**). Risk if future sync logs payloads (**Inferred**). `report($e)` on import abort may land in logs. |
| Retention | **Not defined** in application code (log rotate is ops) |
| Evidence | `config/logging.php`; `.env.example`; `HandleShopifyWebhook` log calls |
| Status | **Verified** defaults; production shipping of logs **External** |

### 1.17 Frontend local storage

| Item | Detail |
|------|--------|
| Location | `sessionStorage` import history; `localStorage` theme preference only (`theme.js`); API uses cookies — “no tokens in localStorage” (`api.js`) |
| Personal data | Import history: import ids/status/counters from API resource (rows omitted). Theme: non-PII. |
| Evidence | `ImportsPage.jsx`, `lib/api.js`, `theme/theme.js` |
| Status | **Verified** |

### 1.18 Backups and snapshots

| Item | Detail |
|------|--------|
| Finding | **No** backup/snapshot/runbook procedures documented in `inboxly-backend/docs` or verified automation in-repo |
| Status | **External** / documentation gap |

### 1.19 Shopify compliance requests (M1)

| Item | Detail |
|------|--------|
| Location | `shopify_compliance_requests`, `shopify_compliance_request_events`; private disk `compliance/{id}/` |
| Personal data | Platform ids (`shopify_customer_id`, data_request id); ephemeral encrypted correlation file (email/phone) deleted after processing; inventory artifact may list contact ULIDs |
| Deletion | Audit events: Eloquent append-only + DB `restrictOnDelete` on parent; correlation forgotten after process |
| Reliability | Processing lease + token ownership; correlation retained until **owned** finalization; `shop/redact` durable step checkpoints; scheduled recover; ops report without PII paths; runtime config guard warnings |
| NULL-store | `workspace_id` set only from matched Store at intake; unassigned rows exist but are not Admin-API visible — escalate via [M1-COMPLIANCE-OPERATIONS.md](M1-COMPLIANCE-OPERATIONS.md) |
| Status | **Verified** implemented in M1 — Partner webhook subscription **not** activated by this repo |

### 1.20 Planned Shopify customer sync tables (not present)

| Item | Detail |
|------|--------|
| Finding | No `shopify_customer_identities`, conflicts, sync runs, or Customer Events handlers |
| Status | **Verified absent** — required before customer PII sync (later milestones) |

---

## 2. Soft delete vs hard delete vs cascade (summary)

| Mechanism | Effect on PII | Evidence |
|-----------|---------------|----------|
| Contact soft-delete | PII retained; unique indexes still hold; consents/events/recipients remain | `ContactController::destroy`; SoftDeletes |
| Contact hard-delete | Cascades consents, events, tag pivots, recipients | FK `cascadeOnDelete` |
| ConsentEvent Eloquent delete | Blocked in app | `ConsentEvent::booted` |
| Store soft-delete | Clears Shopify credentials/identity on model boot; **does not** cascade-delete contacts | `Store::booted`; DATABASE.md |
| Store hard-delete | Cascades contacts and most store children | DATABASE.md / migrations |
| Export prune | Deletes expired CSV + DB row after expiry window | `PruneContactExportsCommand` |

**Verified rule for all future privacy work:** soft delete must never be documented or implemented as GDPR/CPRA erasure.

---

## 3. Privacy workflow requirements (M1 implemented + remaining gaps)

### 3.1 `customers/data_request` — **Implemented (M1)**

1. HMAC + durable `shopify_compliance_requests` + delivery claim.
2. Match contacts/suppressions in-memory via ephemeral encrypted correlation; no email/phone columns on the request row.
3. Private inventory artifact; Admin API exposes `inventory_artifact_ready` + counts only.
4. `completed` only after artifact verified. Merchant delivery remains **out_of_band** (`legal_checklist.merchant_delivery`).

### 3.2 `customers/redact` — **Implemented (M1) → `awaiting_policy`**

1. Durable tracking; does **not** delete Inboxly contacts or consent history.
2. No Shopify identity table yet — Shopify-derived erase is a no-op step with checkpoint note.
3. Ends in `awaiting_policy` while linked-contact / consent / GID retention decisions remain open. Never uses `blocked_legal`.
4. Scheduled recovery **does not** override `awaiting_policy`. Escalate via legal/product; see ops runbook.

### 3.3 `shop/redact` — **Implemented (M1) in-app scope**

1. Disconnects credentials when store found; purges OAuth states and contact export files for the store.
2. `completed` for approved **in-app** steps only; `legal_checklist` records external backups/logs as `ops_responsibility` and does **not** claim organization-wide erasure.
3. External backup deletion remains **External**.

### 3.4 Normal Shopify customer deletion (non-redact)

| Requirement | Status |
|-------------|--------|
| Record deletion/unlink separately from privacy redact | Future identity `unlinked` — **Recommendation** |
| Block stale sync/event recreate | **Recommendation** |
| Do not delete Inboxly contact | **Approved** |
| Do not alter Inboxly consent | **Approved** |
| Admin unlink ≠ Shopify delete | **Approved** |

---

## 4. Decision register (unresolved)

| # | Decision | Owner | Notes |
|---|----------|-------|-------|
| 1 | Does `customers/redact` require erasing a **linked** Inboxly contact? | Legal + product | **External** — blocked for implementation assumptions |
| 2 | Must consent rows / consent_events be erased, retained, or minimized on redact? | Legal | Append-only app semantics ≠ legal retention |
| 3 | May a Shopify Customer GID be retained after redaction? | Legal + platform | Hashing/pseudonymization not automatically anonymous |
| 4 | Backup deletion/expiry obligations for MySQL, disks, log archives | Legal + ops | **External** — no in-repo backup docs |
| 5 | Data-request delivery method and responsible party | Product + ops | Shopify expects merchant-facing fulfillment |
| 6 | App Store vs custom distribution (mandatory compliance webhooks) | Platform | Shopify App Store requires compliance topics |
| 7 | Production compliance webhook ownership (TOML/Partner), HTTPS endpoints, activation before customer sync | Engineering + platform owner | No `shopify.app.toml` in repo today (**Verified** absent) |
| 8 | Failed-import `options.rows` retention | Product/security | Technical gap; not legal |
| 9 | Whether suppressions matching a redacted address must be deleted | Legal + product | Address-keyed, no contact FK |

---

## 5. External / operational dependencies

- Shopify Partner/Dev Dashboard: compliance subscription, PCD approval, Events configuration.
- Hosting: TLS, log rotation, disk backup/restore, queue workers, scheduler (`contact-exports:prune`, OAuth prune, `shopify:recover-compliance-requests`).
- Email/SMTP for invitations and future data-request delivery.
- No verified third-party error tracker in-repo.

---

## 6. Recommended future controls (not in M0 scope)

1. M1: compliance webhook handlers + durable `shopify_compliance_requests` with auditable completion.
2. Clear `contact_imports.options.rows` on failure or encrypt at rest with TTL.
3. Privacy inventory service spanning contacts (withTrashed), consents, events, suppressions, recipients, Brevo events, exports on disk.
4. Logging policy: never log raw customer payloads.
5. `failed_jobs` prune + exception scrubbing.
6. Document and test backup erase runbooks (**External**).

---

## 7. Related documents

- [SECURITY.md](SECURITY.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [ROADMAP-AND-GAPS.md](ROADMAP-AND-GAPS.md)
- [DATABASE.md](DATABASE.md)
- [APPLICATION-FLOWS.md](APPLICATION-FLOWS.md)
