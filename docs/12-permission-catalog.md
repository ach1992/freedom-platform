# Permission Catalog

Document status: `planned`  
Implementation/test status: `not-started`  
Related requirements: `ACL-001`–`ACL-003`, `ADM-001`, `ADM-002`, `SEC-002`

## Authorization semantics

- Default is deny. Commercial account type/tier/tag never grants administrative authority.
- An administrator may hold multiple roles. Role grants are unioned, then a per-administrator override is applied: `inherit`, `allow`, or `deny`; explicit `deny` wins.
- Every command re-authorizes the authenticated Telegram actor and current target/state. Menu visibility, cached permission, callback possession, or prior screen access is not authorization.
- Owner is a protected singleton role. Ownership transfer is a separate hardened, confirmed, audited flow; it is not ordinary role assignment.
- Sensitive actions use short-lived actor/action/target-bound confirmation. Policy may additionally require a different authorized approver.
- View, reveal, export, configure, approve, and execute permissions remain separate. Search results are field-masked before rendering.
- Scheduler/workers use narrow service capabilities, not administrator roles.

## Seed role intent

`O` = seed grant, `—` = no seed grant. Grants remain subject to execution context and sensitive controls.

| Role | Intended scope |
|---|---|
| Owner / Super Admin | Full system authority and protected ownership functions |
| Support | Tickets and minimum customer/service context needed to support users |
| Finance | Payments, receipts, wallet/ledger, refunds, reconciliation, financial reports |
| Technical Operations | Panels, provisioning, services, health, queues, backup operations as delegated |
| Sales and Content | Catalog/pricing, agents, promotions, content, channels, broadcasts, commercial reports |

## Permission registry

### Identity, customer, agent, and access control

| Key | Capability | Owner | Support | Finance | Tech Ops | Sales | Sensitive control |
|---|---|---:|---:|---:|---:|---:|---|
| `customers.view_basic` | View IDs, names, status, tier, tags, dates | O | O | O | O | O | Mask by field |
| `customers.view_verified_phone` | View masked verified phone/status | O | O | O | — | — | Audit access |
| `customers.reveal_identity` | Reveal narrowly allowed phone/card/national-ID value | O | — | — | — | — | Re-auth + reason + audit |
| `customers.manage_status` | Limit/suspend/block/restore customer | O | — | — | — | — | Confirm + reason |
| `customers.manage_tags` | Assign/remove customer tags | O | — | — | — | O | Confirm + before/after |
| `customers.manage_tier` | Override/lock/release tier | O | — | — | — | O | Reason + audit |
| `identity.request_reverification` | Invalidate proof and request re-verification | O | — | — | — | — | Confirm + reason |
| `identity.review` | Approve/reject pending identity items | O | — | O | — | — | Reason + audit |
| `identity.release_phone` | Release normalized phone uniqueness claim | O | — | — | — | — | Re-auth + dual approval optional |
| `agents.view` | View applications/profile/pricing/report | O | O | O | — | O | Field masking |
| `agents.review` | Claim/approve/reject/release application | O | — | — | — | O | Reason; approve atomic |
| `agents.manage_status` | Suspend/restore agent | O | — | — | — | O | Confirm + reason |
| `agents.manage_pricing` | Assign/edit pricing profile and limits | O | — | — | — | O | Preview + audit |
| `admins.view` | View administrators/roles/effective permissions | O | — | — | — | — | No secret disclosure |
| `admins.manage` | Enable/disable admins and assign roles/overrides | O | — | — | — | — | Re-auth + confirm |
| `admins.transfer_ownership` | Transfer Owner identity | O | — | — | — | — | Hardened dual confirmation |
| `roles.manage` | Create/edit roles and grants | O | — | — | — | — | Re-auth + diff preview |

### Orders, payments, wallet, and promotions

| Key | Capability | Owner | Support | Finance | Tech Ops | Sales | Sensitive control |
|---|---|---:|---:|---:|---:|---:|---|
| `orders.view` | View order/item/quote/payment-safe state | O | O | O | O | O | Mask evidence/finance detail |
| `payments.view` | View intents, attempts, transactions, safe evidence | O | — | O | — | — | Sensitive fields masked |
| `payment_evidence.view_private` | View private receipt/gift evidence | O | — | O | — | — | Audited, no export by default |
| `payment_evidence.reveal_code` | Reveal recoverable gift-card code | O | — | — | — | — | Re-auth + reason + audit |
| `receipts.review` | Approve/reject/link/detach card submission | O | — | O | — | — | Reason + transactional decision |
| `gift_cards.review` | Approve/reject/retry/escalate gift submission | O | — | O | — | — | Reason + transactional decision |
| `payment_matches.override` | Override automatic bank/gift match | O | — | O | — | — | Evidence + reason + optional dual approval |
| `payment_providers.manage` | Configure gateway/provider/rate source/destination | O | — | O | — | — | Secret-safe + test before enable |
| `payment_providers.manage_secrets` | Add/rotate/revoke provider credentials | O | — | — | — | — | Re-auth; never reveal full |
| `payments.reconcile` | Run payment/provider reconciliation and safe retry | O | — | O | — | — | Idempotent + audit |
| `refunds.approve` | Approve full/partial refund | O | — | O | — | — | Preview + reason + threshold approval |
| `refunds.override_destination` | Change method-specific refund destination | O | — | — | — | — | Re-auth + reason + dual approval optional |
| `wallet.view_balance` | View bucket balances | O | O | O | — | — | Minimum necessary |
| `wallet.view_ledger` | View ledger entries/reconciliation | O | — | O | — | — | Export separate |
| `wallet.correct` | Credit/debit via compensating ledger transaction | O | — | O | — | — | Preview + reason + threshold approval |
| `financial_reports.view` | View defined financial reports | O | — | O | — | O | Sales may receive aggregates only |
| `financial_exports.create` | Create audited expiring financial export | O | — | O | — | — | Mask + retention |
| `promotions.manage` | Manage discount/gift codes and rules | O | — | — | — | O | Preview + audit |
| `referrals.manage` | Manage rules/corrections before lock | O | — | — | — | O | Reason + audit |

### Catalog, panels, provisioning, and services

| Key | Capability | Owner | Support | Finance | Tech Ops | Sales | Sensitive control |
|---|---|---:|---:|---:|---:|---:|---|
| `catalog.view` | View categories/products/offerings/packages | O | O | O | O | O | — |
| `catalog.manage` | Create/edit/order/archive catalog and prices | O | — | — | — | O | Preview + history |
| `servers.view` | View servers/targets/capabilities/capacity/health | O | O | — | O | O | Credentials excluded |
| `panels.manage` | Configure panels, targets, fallback, capacity | O | — | — | O | — | Confirm; credentials encrypted |
| `panels.manage_secrets` | Add/rotate/revoke panel credentials/CA/pin | O | — | — | — | — | Re-auth; never reveal full |
| `panels.test` | Test connection/discover capabilities/version | O | — | — | O | — | Safe redacted result |
| `services.view` | Search/view customer service and cached usage | O | O | O | O | O | Subscription link separately protected |
| `services.view_delivery_secret` | View/resend subscription/config links | O | O | — | O | — | Audit; never log |
| `services.operate` | Sync/reset/activate/suspend/refresh where allowed | O | — | — | O | — | Confirm destructive action |
| `services.rotate_link` | Revoke/regenerate subscription link | O | — | — | O | — | Confirm outage warning |
| `services.retire` | Delete/retire remote service | O | — | — | O | — | Confirm + reason; preserve history |
| `services.retry_provisioning` | Retry/reconcile failed/uncertain provisioning | O | — | — | O | — | Discover remote first |
| `services.repair` | Apply validated allowlisted metadata repair | O | — | — | O | — | Before/after evidence |
| `services.transfer_ownership` | Transfer service to another customer | O | — | — | — | — | Explicit validation + audit |
| `services.grant_single` | Create complimentary/manual service | O | — | — | O | — | Source + reason + confirm |
| `services.grant_batch` | Create/resume/cancel/retry batch services/data/days | O | — | — | O | O | Scope preview + threshold approval |

### Support, communication, content, and reporting

| Key | Capability | Owner | Support | Finance | Tech Ops | Sales | Sensitive control |
|---|---|---:|---:|---:|---:|---:|---|
| `tickets.view` | View queue and permission-filtered customer context | O | O | — | — | — | Own assignment/routing policy |
| `tickets.manage` | Claim/assign/transfer/reply/note/state/close | O | O | — | — | — | Internal-note separation |
| `tickets.manage_categories` | Manage categories/routing/SLA/canned responses | O | O | — | — | — | Audit changes |
| `messages.send_direct` | Send confirmed direct message to one customer | O | O | — | — | O | Target preview + result record |
| `broadcasts.view` | View campaigns/audiences/results | O | — | — | — | O | Audience data masked |
| `broadcasts.manage` | Create/test/schedule/start/pause/resume/cancel | O | — | — | — | O | Estimate + Owner test + confirm |
| `broadcasts.mutate_delivered` | Edit/buttons/pin/unpin/delete delivered messages | O | — | — | — | O | Platform-limit check; high-risk confirmation |
| `content.manage` | Edit/preview/version/reset localization/content | O | — | — | — | O | Placeholder/markup validation |
| `menus.manage` | Manage system/custom menus/buttons/resources | O | — | — | — | O | Allowlisted actions; navigation safety |
| `channels.manage` | Manage required channels/membership policy | O | — | — | — | O | Bot capability test + failure policy |
| `reports.view_operational` | View non-financial operational/commercial reports | O | O | O | O | O | Dimension/field restrictions |
| `reports.export` | Create audited expiring CSV/XLSX export | O | — | O | O | O | Mask + minimum necessary |
| `reports.schedule` | Schedule permission-scoped report delivery | O | — | O | O | O | Destination test + audit |

### Operations, security, backup, install, and update

| Key | Capability | Owner | Support | Finance | Tech Ops | Sales | Sensitive control |
|---|---|---:|---:|---:|---:|---:|---|
| `operations.view` | View health, queue, jobs, alerts, version, backup state | O | — | O | O | — | Secret-redacted |
| `operations.retry` | Retry/reconcile safe failed jobs/operations | O | — | O | O | — | Domain-specific permission also required |
| `alerts.acknowledge` | Acknowledge/resolve assigned alerts | O | — | O | O | — | Reason + audit |
| `audit.view` | View immutable audit log | O | — | O | O | — | Subject/field filtering |
| `audit.export` | Export audit data | O | — | — | — | — | Re-auth + reason + retention |
| `settings.manage_global` | Change global policy/settings | O | — | — | — | — | Diff preview + confirmation |
| `secrets.manage_global` | Rotate application/internal integration secrets | O | — | — | — | — | Re-auth; dual approval optional |
| `backups.run` | Run backup and view redacted result | O | — | — | O | — | No key/plaintext exposure |
| `backups.manage_policy` | Configure contents/destination/retention/key reference | O | — | — | — | — | Re-auth + destination test |
| `restores.run` | Dry-run and execute restore | O | — | — | — | — | Re-auth + maintenance + dual approval |
| `updates.install` | Verify/stage/activate update | O | — | — | — | — | Re-auth + backup + active-work check |
| `updates.rollback` | Roll back compatible release/restore snapshot | O | — | — | — | — | Re-auth + schema compatibility + dual approval |
| `security.manage_policy` | Change auth, rate-limit, SSRF, upload, approval policies | O | — | — | — | — | Diff + re-auth + audit |

## Contextual authorization conditions

Possessing a permission is necessary but not sufficient. The policy must also enforce:

- actor is active and Telegram identity/chat binding is current;
- target exists, is in an allowed state, and version/optimistic lock matches;
- ownership for customer actions and assignment/routing scope for support actions;
- amount/batch thresholds, independent approver, and separation of requester/approver;
- provider/adapter capability and health, offering policy, and configuration version;
- no expired callback/session/confirmation and no already-consumed idempotency key;
- field-level privacy, masking, and separate reveal/export permission;
- maintenance/incident freezes for affected financial automation.

## Sensitive approval matrix

Exact thresholds are configuration and require Owner decision `DEC-BIZ-001`. Until configured, the safest behavior is to require Owner approval for high-impact operations.

| Action class | First confirmation | Re-auth | Independent approval | Required evidence |
|---|---:|---:|---:|---|
| View/reveal identity or gift code | Yes | Yes | Policy-based | Actor, reason, fields revealed |
| Wallet correction/refund override/manual match override | Yes | Policy-based | Threshold-based | Amount, bucket/destination, reason, before/after |
| Provider/panel/global secret rotation | Yes | Yes | Policy-based | Secret reference/version only, connection test |
| Service ownership transfer or destructive retirement | Yes | Policy-based | Policy-based | Source/target, remote verification, reason |
| Large batch grant/broadcast destructive lifecycle | Yes | Policy-based | Threshold-based | Estimated scope, test result, per-target result |
| Restore/update/rollback | Yes | Yes | Recommended | Package/backup integrity, compatibility, pre/post checks |
| Owner transfer | Two-step | Yes | Receiving-owner acceptance | Old/new Owner IDs, signed intent, correlation ID |

## Required authorization tests

- Multi-role union, user `allow`, user `deny`, and deny precedence.
- Menu hidden but direct callback attempted; stale/replayed/forged callback; permission revoked between preview and execute.
- Cross-customer IDOR for ticket/order/payment/service/export/evidence.
- Field masking with basic/view/reveal/export combinations.
- Requester cannot satisfy required independent approval; concurrent approvals create one effect.
- Owner transfer cannot create zero or two active Owners.
- Worker/service authority cannot call unrelated administrative commands.
- Permission cache invalidates immediately on role/override/admin-status changes.

All tests and implementation are currently `not-started`.
