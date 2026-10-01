# Version 1 Operations, Administration, and Support Guide

This guide is the durable human-facing operating map for Freedom Platform Version 1. It explains where operators should act and where the authoritative details live. It does not replace execution-time authorization, the deployment runbook, or live environment inventory.

## Canonical authorities

| Need | Authority |
| --- | --- |
| Product/behavior requirement | [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) and [`01-authoritative-requirements.md`](01-authoritative-requirements.md) |
| Architecture/state/transaction boundaries | [`05-architecture-overview.md`](05-architecture-overview.md) |
| Security and sensitive operations | [`07-security-threat-model.md`](07-security-threat-model.md) |
| Sensitive-data handling | [`08-data-classification.md`](08-data-classification.md) |
| Durable risk decisions | [`03-risk-register.md`](03-risk-register.md) |
| Schema and relationships | [`reference/data-dictionary.md`](reference/data-dictionary.md) |
| Permission catalog/default role grants | [`reference/permission-catalog.md`](reference/permission-catalog.md) |
| Deployment, backup/restore, update/rollback | [`09-deployment-runbook.md`](09-deployment-runbook.md) |
| Code/module navigation | [`development/repository-map.md`](development/repository-map.md) |

Live priority, release state, CI evidence, host inventory, deployed secrets, credentials, and current incidents belong to GitHub or the relevant operating system/service, not this document.

## Access and authorization

Freedom Platform uses execution-time authorization. Telegram menu visibility, a default role, or possession of an administrator account is never sufficient authority for a sensitive action.

The generated [permission catalog](reference/permission-catalog.md) shows the current seeded permission definitions and default active role grants. Runtime authorization additionally considers active administrator status, role assignments, per-administrator allow/deny overrides, current permission version, sensitive-action approval when required, and domain/state preconditions.

Permissions with no default role are intentionally not implied to anyone. Granting, overriding, or approving access must use the product Access Control authority; do not edit permission, role-assignment, or override rows manually.

## Telegram operating surface

Telegram is the primary Version 1 product/admin surface. Customer-visible text is localization-owned and menu layout can be versioned/configured, so this guide intentionally does not hard-code button labels or menu positions.

### Administrator capability groups

Use the current Telegram administrator navigation and the viewer's effective permissions for these groups:

- **Customer/identity:** customer lookup, status/tier/tag management, verification evidence, and masked sensitive-data access.
- **Catalog/sales:** products, offerings, routes, promotions, benefit codes, agent/reseller lifecycle and pricing.
- **Finance:** payment lookup, provider/payment configuration, refunds, wallet corrections, reconciliation, and financial reporting.
- **Services/technical:** panels, servers/targets, service operations, synchronization, reconfiguration, retirement, link rotation, import/repair and grants.
- **Support:** ticket lifecycle, customer communication and SLA/material-delivery alerts.
- **Telegram/content:** broadcasts, direct messages, membership rules, menu configuration and localization.
- **Reporting/operations:** permission-aware reports, schedules, alerts, Operations Center and runtime health.

Search permissions are surface-specific. A search result may be visible while sensitive evidence in the same domain remains masked or denied.

### Support workflow

Support operators should work through ticket and administrator-search authorities rather than direct database inspection.

1. Identify the customer/order/service/payment through permission-aware administrator search.
2. Use the ticket lifecycle for customer communication and durable support state.
3. Escalate financial, provider, provisioning or security anomalies to the owning authority instead of editing business rows.
4. Use correlation/public identifiers from safe application output when handing an incident to technical operations.
5. Keep restricted evidence inside its authorized product boundary; do not copy private media, subscription links, credentials, or raw provider payloads outside that boundary.

`support:alerts:scan` is the repository-owned scan for bounded Support SLA and material customer-delivery alert conditions.

## Business lifecycle reference

The normative Version 1 state-machine documentation is in the master specification, especially section 9 for order/payment/provisioning separation and guarded order/payment states, and section 21 for broadcast recipient/message lifecycle. The source enums below are the implementation entry points used to verify the currently accepted states and must not be bypassed by direct persistence edits.

The durable lifecycle rules live in domain/application code and tests. These are the principal state owners an operator or maintainer should start from:

| Domain | Primary state owner |
| --- | --- |
| Orders | `app/Modules/Orders/Domain/OrderState.php` |
| Payment intents | `app/Modules/Payments/Domain/PaymentIntentState.php` |
| Zarinpal | `app/Modules/Payments/Zarinpal/Domain/ZarinpalRequestState.php` |
| Provisioning | `app/Modules/Provisioning/Domain/ProvisioningState.php` |
| Service auto-renew | `app/Modules/Provisioning/Domain/AutoRenewAttemptState.php` |
| Service delivery effects | `app/Modules/Provisioning/Domain/ServiceDeliveryEffectState.php` |
| Support tickets | `app/Modules/Support/Domain/SupportTicketState.php` |
| Telegram broadcasts | `app/Modules/Telegram/Domain/TelegramBroadcastCampaignState.php` |
| Telegram delivery operations | `app/Modules/Telegram/Domain/TelegramDeliveryOperationState.php` |
| Wallet state | `app/Modules/Wallet/Domain/` |
| Agent state | `app/Modules/Agents/Domain/` |
| Promotions/benefit codes | `app/Modules/Promotions/Domain/` |

Do not force state by editing tables. State transitions carry authorization, idempotency, financial/provider evidence, audit and concurrency invariants that a row edit cannot reproduce.

## Installation and deployment handover

The supported production target is Ubuntu/aaPanel/OpenLiteSpeed with PHP 8.4, MariaDB >=10.11.9 and authenticated Redis. The executable installation/deployment authority is [`09-deployment-runbook.md`](09-deployment-runbook.md), including release layout, writable/persistent paths, worker/Scheduler templates, health gates and protected operations. Development bootstrap belongs to [`../CONTRIBUTING.md`](../CONTRIBUTING.md); do not treat development setup as a production installation recipe.

## Runtime health, Scheduler, and workers

The deployment model uses one Laravel Scheduler Cron entry plus supervised Redis queue workers. Canonical templates are:

- `deploy/cron/freedom-platform.cron`;
- `deploy/supervisor/freedom-platform.conf`;
- `deploy/bin/queue-worker-with-heartbeat.sh`.

The production Cron/Supervisor installation path and permissions are governed by the [deployment runbook](09-deployment-runbook.md). Do not copy template paths blindly to another installation without reconciling its approved release root.

Useful source-backed commands include:

| Command | Purpose |
| --- | --- |
| `php artisan health:check --critical --json --redact` | Fail-closed application/DB/Redis/runtime readiness check |
| `php artisan operations:check-worker-heartbeats` | Detect stale workers and persist deduplicated critical alerts |
| `php artisan operations:dispatch-outbox` | Dispatch a bounded batch from the common Transactional Outbox |
| `php artisan operations:deliver-alerts` | Queue persisted operational alert delivery |
| `php artisan services:sync` | Synchronize eligible services or a selected service |
| `php artisan services:auto-renew` | Process due wallet-funded auto-renewals |
| `php artisan services:notifications` | Process service notification thresholds/retries |
| `php artisan reporting:run-schedules` | Queue due permission-aware reports |
| `php artisan telegram:process-broadcasts` | Process bounded broadcast lifecycle/delivery work |
| `php artisan telegram:updates:requeue` | Requeue stranded Telegram updates |
| `php artisan telegram:webhook:configure --status-only --json` | Inspect webhook status without changing it |

Use `php artisan <command> --help` from the exact deployed release before relying on optional arguments. Command transport success does not override the acceptance criteria owned by that command.

## Backup, restore, update, and rollback

The complete safety contract is in [`09-deployment-runbook.md`](09-deployment-runbook.md). Keep these distinctions:

- a backup existing is not proof that it is restorable;
- Telegram backup export is a secondary protected copy, not backup authority;
- restore/update/rollback preflight is deliberately separate from `--apply`;
- apply operations require exact confirmations and can enter maintenance/worker/Scheduler containment;
- package trust starts with an out-of-band complete-package SHA-256 before package parsing;
- schema-incompatible rollback must use protected update recovery/Restore rather than forcing a code switch.

Source-backed entry points:

- `php artisan operations:backup --kind=<frequent_database|daily_full|pre_update> --json`;
- `php artisan operations:restore <backup-id> --json` for dry-run;
- `php artisan operations:update <package> --trusted-sha256=<sha256> --json` for package/update preflight;
- `php artisan operations:update:rollback --json` for rollback preflight;
- `php artisan operations:update:recover <update-run-id> --json` when update authority requires Restore recovery.

Never add `--apply` merely to test a procedure. Apply is consequential and must follow the environment/release gate.

## Incident response

For a correctness, security, financial, provider, queue, update, or data-integrity incident:

1. **Contain the affected effect.** Stop further rollout or the smallest affected processing surface when current authority supports it. Do not improvise destructive DB/provider actions.
2. **Identify exact state.** Record the deployed release, safe correlation/run/public IDs, `health:check` result, relevant Operations Center/alert state, queue/worker health and owning domain.
3. **Preserve authority.** Do not delete/repair financial evidence, Outbox rows, provider attempts, update reports, backups, audit logs or migration fences to make the symptom disappear.
4. **Classify uncertainty.** Provider/network timeout or lost response is not proof of failure. Use authoritative lookup/reconciliation before retrying an external effect.
5. **Use the owning recovery path.** Examples are durable queue retry/manual review, service reconciliation, controlled Restore, update recovery or compatibility-proven rollback.
6. **Verify recovery.** Re-run the applicable health/critical journey and confirm durable state, not only process liveness.
7. **Escalate unresolved material risk.** Critical/High financial, authorization, data, provider, backup/restore or release risk requires an explicit owning Issue/decision; it is never implicitly accepted.

Production diagnostics must remain redacted. Never paste credentials, tokens, private URLs, subscription material, raw restricted payloads or backup contents into incident notes.

## Secret and credential rotation

Secrets live outside Git in the approved environment/secret mechanism. Rotation must preserve recovery and provider authority, not merely replace a string.

1. Identify the exact secret owner/consumer and whether old material is still required to decrypt/verify retained evidence or backups.
2. Establish provider/remote-side replacement first when the external contract requires it.
3. Update the approved deployment secret source; never commit the value.
4. Reload/restart only affected runtime/workers through the deployment operating model.
5. Run `health:check` and the narrow provider/webhook/backup path that proves the new credential works.
6. Revoke the previous credential only after replacement is proven and recovery/retention requirements permit revocation.
7. Record only safe secret identity/version/rotation evidence, never the value.

Do not rotate application encryption, backup encryption/signing, lookup-hash or other retained-data key material ad hoc. Where old material protects durable historical data, use the key/version lifecycle defined by the owning implementation and security/data-retention authority.

## Troubleshooting order

Prefer one discriminating step at a time:

1. `php artisan health:check --critical --json --redact`;
2. inspect the owning safe application/Operations Center signal and correlation/run ID;
3. verify worker/Scheduler state when asynchronous work is involved;
4. verify relevant configuration/permission/provider status without mutating it;
5. use the owning reconciliation/dry-run command;
6. only then perform an authorized corrective action.

Avoid broad retries, repeated full-suite commands, manual database state edits and provider mutations whose previous outcome is uncertain.

## Release and production boundary

This guide makes procedures discoverable; it grants no release authority. GitHub Release publication, production deployment, traffic activation, destructive restore/update, and protected live-provider mutations remain subject to the current Phase/release authorization and evidence.
