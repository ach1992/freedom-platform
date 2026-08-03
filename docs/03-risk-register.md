# Risk Register

**Baseline:** `0.1.0` planning  
**Last reviewed:** 2026-08-02  
**Scope:** product, architecture, security, operations, and delivery risks

## Method

Likelihood and impact are rated `Low`, `Medium`, `High`, or `Critical`. A risk is a release blocker when it can violate a financial invariant, an authorization boundary, provisioning idempotency, restore integrity, or leave a known Critical/High security defect without an approved mitigation. “Open—deferred input” means the design can proceed with a fake or conservative default, but production activation cannot.

| ID | Risk | Likelihood | Impact | Required treatment / control | Owner | Decision point | Status |
|---|---|---:|---:|---|---|---|---|
| `RSK-001` | Duplicate webhook, callback, update, job, or administrator click causes duplicate capture or service | High | Critical | Stable idempotency keys, database unique constraints, guarded transitions, row locking, transactional outbox, remote discovery before provisioning retry | Payments + Provisioning | `0.5.0` / `0.6.0` gates | Open |
| `RSK-002` | Provider returns success but the response is lost, leaving local and remote state divergent | High | Critical | Persist attempts, classify result as uncertain, query authoritative status, reconcile; never repeat a non-idempotent remote create/capture without discovery | Integration owners | Contract implementation | Open |
| `RSK-003` | Wallet race permits negative available balance or double spend | Medium | Critical | Lock wallet account/hold rows, post balanced entries in one MariaDB transaction, unique operation key, concurrency tests, scheduled reconciliation | Financial Integrity | `0.5.0` gate | Open |
| `RSK-004` | Mutable balances or administrative edits corrupt financial history | Medium | Critical | Append-only ledger; correction by compensating transaction; restrictive authorization; immutable audit; derived/reconcilable snapshots only | Financial Integrity | Schema review | Open |
| `RSK-005` | Paid order provisions twice during timeout or worker crash | High | Critical | Unique operation per order item, deterministic remote identity, remote lookup/adoption after uncertainty, unique local remote identity | Provisioning | `0.6.0` gate | Open |
| `RSK-006` | Generic administrator-configured endpoint enables SSRF or DNS rebinding | Medium | Critical | HTTPS-only URL value object, optional domain allowlist, reject credentials/IP literals, resolve all A/AAAA records, block non-global ranges, connect only to validated addresses while preserving TLS hostname, revalidate every redirect, egress firewall | Security | Before Generic REST activation | Open |
| `RSK-007` | Real Marzban or PasarGuard semantics differ from documentation or installed version | High | High | Capability discovery, versioned contract note, target-panel contract tests, fake adapter; unsupported capability is hidden/denied | Panels | Before each real connection activation | Open—deferred input |
| `RSK-008` | Bank/gift provider lacks authoritative reserve/capture semantics | High | Critical | Validation alone never captures; route to manual review unless a documented owner-approved risk policy supplies equivalent assurance | Payments + Owner | Provider onboarding | Open—deferred input |
| `RSK-009` | IRR/Toman or crypto rounding mismatch causes incorrect settlement | Medium | Critical | Integer IRR, explicit unit in every DTO/configuration, fixed decimal crypto, snapshotted conversion, amount/unit contract tests | Financial Integrity | `0.5.0` gate | Open |
| `RSK-010` | Secret or sensitive identifier leaks through logs, reports, screenshots, backups, or provider payloads | Medium | Critical | Central redaction, allowlisted structured fields, encrypted values, keyed lookup hashes, masked presentation, encrypted backups, negative leakage tests | Security + Operations | Every phase | Open |
| `RSK-011` | Compromised administrator performs refund, correction, ownership transfer, restore, or update | Medium | Critical | Default-deny authorization, per-action permission check, signed short-lived confirmation, re-authentication/optional dual approval, immutable audit and alerts | Access Control | `0.3.0` onward | Open |
| `RSK-012` | Redis loss breaks correctness | Medium | High | Redis locks are advisory; MariaDB transaction/unique constraints are final barriers; jobs remain retryable; health/circuit-break behavior documented | Architecture + SRE | Foundation tests | Open |
| `RSK-013` | Malicious receipt or attachment exploits parser/storage | Medium | High | Private storage, random names, MIME sniffing, size limits, no execution, image re-encoding only in isolated worker if adopted, malware-scan hook, authorized delivery | Security | File feature delivery | Open |
| `RSK-014` | Malicious or tampered update/backup gains code execution or corrupts state | Medium | Critical | Signed/checksummed manifests, trusted signing key, authenticated backup encryption, compatibility checks, isolated restore validation, fixed commands only | SRE + Security | `0.8.0` gate | Open |
| `RSK-015` | Atomic code rollback is incompatible with irreversible database migration | Medium | Critical | Expand/contract migrations, compatibility metadata, pre-update backup, block incompatible rollback, rehearse restore | SRE + Database | Every release | Open |
| `RSK-016` | Telegram delivery failure is mistaken for transaction failure | Medium | High | Commit business result first; outbox delivery is separate and retryable; order tracking exposes committed state | Telegram + Architecture | `0.6.0`/`0.7.0` | Open |
| `RSK-017` | Provider webhook forgery or replay changes financial state | Medium | Critical | Verify signature over raw bytes before business parsing, timestamp/nonce window where supported, unique provider event ID/hash, asynchronous idempotent processing, status re-query for sensitive transitions | Payments | Provider contract gate | Open |
| `RSK-018` | OTP abuse creates cost, enumeration, or account takeover | High | High | Hashed OTP, per phone/user/IP/provider limits, fixed responses, attempt ceiling, cooldown, short expiry, no fallback on uncertain send | Identity | `0.3.0` gate | Open |
| `RSK-019` | Exact-amount collision misattributes a card transfer | Medium | Critical | Database-unique active collision key scoped by destination/window, transaction retry, exact amount/destination/time hard match, ambiguity to manual review | Payments + Database | `0.5.0` gate | Open |
| `RSK-020` | One bank transaction or gift-card redemption funds multiple intents | Medium | Critical | Unique provider transaction/redemption identity and unique consumption record; capture and consumption in one transaction | Payments + Database | `0.5.0` gate | Open |
| `RSK-021` | PII is retained longer than justified or is inaccessible for legitimate operations | Medium | High | Data inventory, purpose-limited access, retention jobs, legal hold, audited export/reveal, encrypted backups; owner/legal approval of final schedule | Owner + Security | Before production data | Open—business/legal decision |
| `RSK-022` | Backup exists but cannot be restored within an acceptable window | Medium | Critical | 10-minute DB backup target, checksum/decrypt test, isolated restore rehearsal, measured RPO/RTO, post-restore reconciliation | SRE + Owner | `0.8.0` gate | Open—targets pending |
| `RSK-023` | Broadcast traffic starves payment/provisioning jobs | Medium | High | Separate queue routing and worker budgets, priority isolation, rate limits, backlog alerts | SRE | Load baseline | Open |
| `RSK-024` | Supply-chain dependency or abandoned package introduces vulnerability/license conflict | Medium | High | Minimal dependencies, lockfile, Composer audit, license inventory, provenance/checksum checks, no abandoned packages, review updates | Security + Architecture | CI/release gates | Open |
| `RSK-025` | Telegram Bot API feature/limit changes break message lifecycle or custom emoji | Medium | Medium | Capability checks, dated contract note, graceful fallback, store per-recipient results, do not promise unsupported edits/deletes | Telegram | Implementation and release | Open |
| `RSK-026` | Server has two inconsistent PHP 8.4 runtimes | High | High | Preflight CLI and LSPHP independently for version, extensions, ini, timezone, disabled functions, limits, OPcache | SRE | Installer gate | Open |
| `RSK-027` | Owner-provided production credentials are exposed during onboarding | Low | Critical | Never collect in chat/repository; installer secret fields, hidden Artisan input, or restricted temporary file; verify without echoing | Owner + SRE | Integration/deployment | Controlled |
| `RSK-028` | Unknown production volume invalidates queue/database design | Medium | High | Instrument first; agree target workload, data volume and SLO; run baseline now and target-specific load tests before `1.0.0` | Owner + QA + SRE | `0.9.0` gate | Open—deferred input |
| `RSK-029` | Retention cleanup deletes evidence needed for reconciliation, disputes, or audit | Medium | High | Financial/audit records never hard-delete; retention policy distinguishes content from immutable evidence; legal hold overrides cleanup | Security + Finance | Retention approval | Open |
| `RSK-030` | “Generic REST mapping” becomes arbitrary code execution | Medium | Critical | Declarative allowlisted JSON paths and transformations only; schema validation; no expressions, templates, PHP, SQL, or shell | Architecture + Security | `0.5.0` review | Open |

## Open decisions that do not block `0.1.0`

| ID | Input needed | Safe planning default | Must be resolved before |
|---|---|---|---|
| `DEC-001` | Real bank transaction API and its authority model | Fake provider + disabled Generic REST configuration | Activating automatic card verification |
| `DEC-002` | Real gift-card provider, brand/region/value rules, reserve/capture support | Fake provider + manual fallback | Activating automatic gift-card capture |
| `DEC-003` | High-value thresholds and dual-approval thresholds | Feature enabled but conservative threshold remains deployment configuration | Live financial operation |
| `DEC-004` | PII/media/provider-payload retention and applicable legal obligations | Provisional schedule in `08-data-classification.md`; preserve financial/audit evidence | Production data collection |
| `DEC-005` | Target workload, RPO, RTO, and maintenance window | Design for horizontal workers; measure a baseline without claiming capacity | `0.9.0` certification |
| `DEC-006` | Installed Marzban/PasarGuard versions and credentials | Contract + fake implementations | Live adapter activation |
| `DEC-007` | Signing key custody and backup-key custody | Separate keys, never in repository or same backup | First signed release / backup |

## Review cadence

- Update on every phase gate and after any security, financial, provider, schema, restore, or release incident.
- A closed risk must cite the control, automated evidence, and reviewer; documentation alone does not close implementation risk.
- Accepted residual High/Critical risk requires explicit Owner acceptance and cannot waive the Master Prompt's release blockers.
