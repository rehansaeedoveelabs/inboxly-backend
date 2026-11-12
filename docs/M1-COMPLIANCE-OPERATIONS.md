# M1 Shopify compliance — operational runbook

This runbook covers **in-app** M1 compliance processing reliability. It does **not** authorize Partner/TOML webhook activation or real customer privacy fulfillment.

**Automated recovery ≠ alerting.** `shopify:recover-compliance-requests` redispatches eligible stranded work. `shopify:compliance-ops-report` is an operator inspection tool. Neither replaces metrics, pages, or on-call.

## Processing lease

| Knob | Default | Rule |
|------|---------|------|
| `ProcessShopifyComplianceRequestJob::$timeout` | 120s | Job wall clock (Laravel `pcntl_alarm` when available) |
| `SHOPIFY_COMPLIANCE_PROCESSING_LEASE_SECONDS` | 300 | Must be ≥ job timeout + 60 (claim also enforces this floor) |
| Database queue `retry_after` | 360 | Must be **greater than** the compliance job timeout (and ≥ longest job on the connection) |
| Queue worker `--timeout` | (operator) | Must be ≥ job timeout; **not** used in lease math |

**Lease calculation uses the job’s declared `$timeout`, not an arbitrary deployed `queue:work --timeout`.** Application code cannot reliably detect every host’s worker CLI flags. `ShopifyComplianceRuntimeGuard` logs warnings for missing `pcntl`, unsafe `retry_after`, and non-database queue drivers at boot (non-testing).

### Staging / production requirements (explicit)

1. PHP with working **`pcntl_alarm` / `pcntl_async_signals`** so job `$timeout` is enforced.
2. Prefer **`QUEUE_CONNECTION=database`** with `DB_QUEUE_RETRY_AFTER` > 120 (360 default). Other drivers only if their `retry_after` is verified greater than the job timeout.
3. Processing lease (300 default) must exceed the maximum time a **healthy** worker may still execute this job (job timeout under pcntl).
4. Do not assume configuration is safe when `pcntl` is missing or `retry_after ≤ timeout` — treat logged warnings as blockers for staging activation.

When a worker claims a request it sets `status=processing`, `processing_lease_expires_at`, and `processing_lease_token`. Finalize/fail and correlation cleanup succeed only while that token still owns the row.

**Recovery must not reclaim while a healthy worker may still be running.** Only `processing` rows with an **expired** lease (or legacy rows whose `processing_started_at` is older than the lease window) are eligible.

Checkpoints are preserved across reclaim. `customers/data_request` keeps encrypted correlation until **owned** finalization so crash replay can rebuild a complete inventory. `shop/redact` persists per-step checkpoints under the lease before continuing.

## Scheduled recovery

Registered in `bootstrap/app.php`:

- `shopify:recover-compliance-requests` every five minutes
- `withoutOverlapping(10)` so concurrent recover runs do not stack

**Production requirement:** a process must run `php artisan schedule:run` every minute (cron/systemd). Without the scheduler, stranded `received` and expired-lease `processing` rows are not rediscovered automatically.

Eligible recovery targets:

1. `received` older than `SHOPIFY_COMPLIANCE_RECOVER_RECEIVED_OLDER_THAN` (default 60s)
2. `processing` with expired / legacy-missing lease

Never recovered by this command: `awaiting_policy`, `completed`, live-lease `processing`.

Manual: `php artisan shopify:recover-compliance-requests [--older-than=] [--limit=]`

Recovery writes non-PII `recovery_dispatched` audit events. If dispatch fails, the row stays eligible for the next run (not silently dropped).

## Operator inspection

```bash
php artisan shopify:compliance-ops-report
php artisan shopify:compliance-ops-report --json --received-older-than=3600
```

Buckets (counts + safe samples):

- Old `received`
- Expired / stuck `processing`
- `failed`
- `awaiting_policy`
- NULL `store_id` + NULL `workspace_id`

Safe fields only: request id, `shop_domain`, topic, status, store/workspace ids (or null), timestamps, `error_code`. Never prints webhook bodies, emails, phones, tokens, correlation contents, or private artifact paths.

## NULL-workspace / NULL-store requests

Intake sets `workspace_id` only from a matched Store. Unknown shops (common for late `shop/redact` after soft-delete cleared `shopify_shop_domain`) create rows with both ids null.

- Workspace Admin APIs remain store-scoped and **do not** list these rows (no cross-tenant exposure).
- Do **not** invent ownership from `shop_domain` history.
- Escalate manually: ops report → note `id` + `shop_domain` + status → legal/platform owner decides binding; engineering may inspect DB/logs by id only.

## `awaiting_policy`

`customers/redact` intentionally ends in `awaiting_policy` until legal decisions on linked-contact erase, consent erase, and GID retention are resolved. Recovery does **not** override this status. Escalate via product/legal; Admin retry is rejected (409) for non-`failed` rows.

## Failure visibility

| Signal | How to find |
|--------|-------------|
| Stranded `received` | Ops report + scheduled recover |
| Expired `processing` | Ops report + scheduled recover + lease reclaim on job |
| `failed` | Ops report; Admin retry (Admin only) |
| `awaiting_policy` | Ops report; legal escalation |
| NULL workspace | Ops report only (not Admin API) |
| Queue exhaustion | `failed_jobs` + job `failed()` logs (`compliance_request_id`, error code) |

## Limitations

- Commands are not paging/alerting.
- External backups/log archives remain ops responsibility (`shop/redact` does not claim them).
- Partner Dashboard / TOML subscription activation is out of band.
- No M2 customer identity sync in this milestone.
