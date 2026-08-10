# Risk Register

**Baseline:** Version `1.0.0` product and delivery specification  
**Last reviewed:** 2026-08-10  
**Document role:** durable risk definitions, controls, decision gates, and review history. This is not a live task board.

Current phase, active tasks, exact SHAs, blockers, and CI state belong only in `PROJECT_STATUS.md`, `docs/project-status.json`, and live GitHub.

## Risk method

Likelihood and impact are rated `Low`, `Medium`, `High`, or `Critical`.

Lifecycle values mean:

- `Open` — required control or complete evidence is still missing;
- `Partially mitigated` — accepted foundations reduce the risk, but later owned behavior can still violate it;
- `Mitigated—monitor` — an accepted control exists for the implemented boundary and must remain under regression/release review;
- `Deferred input` — safe implementation can continue with fakes/conservative defaults, but production activation requires external/Owner input;
- `Controlled` — governance/handling controls exist continuously; a future violation reopens the risk.

No High/Critical risk is considered closed merely because documentation, a class, a fake, or a test exists. Closure requires applicable implementation evidence and review; accepted residual High/Critical risk requires explicit Owner acceptance and cannot waive normative release blockers.

## Durable risk register

| ID | Risk | Likelihood | Impact | Required control | Primary gate | Lifecycle |
|---|---|---:|---:|---|---|---|
| `RSK-001` | Duplicate webhook/callback/job/operator action creates a second financial or remote effect | High | Critical | Stable idempotency, uniqueness, guarded transitions, row locks, transactional outbox, replay/conflict tests | payment + provisioning | Partially mitigated |
| `RSK-002` | External provider succeeds but response is lost, leaving local/remote state divergent | High | Critical | Persist attempts; classify uncertain; authoritative lookup/discovery/reconciliation before retry | every mutating integration | Open |
| `RSK-003` | Wallet race permits negative available balance or double spend | Medium | Critical | MariaDB locking/transactions, balanced append-only entries, unique operation identity, contention tests, reconciliation | financial integrity | Mitigated—monitor |
| `RSK-004` | Mutable balances/admin edits corrupt financial history | Medium | Critical | Append-only ledger; correction through compensating transactions; restricted authorization; immutable audit | financial integrity | Mitigated—monitor |
| `RSK-005` | Paid order provisions twice after timeout/worker crash | High | Critical | Unique operation per item, deterministic remote identity, discovery/adoption after uncertainty | Order/Provisioning gate | Open |
| `RSK-006` | Generic configured endpoint enables SSRF/DNS rebinding | Medium | Critical | HTTPS-only typed endpoint, no credentials/IP literals, DNS/global-address validation, redirect revalidation, TLS hostname preservation, egress controls | Generic REST activation | Open |
| `RSK-007` | Installed Marzban/PasarGuard semantics differ from source/docs/contracts | High | High | Exact installed version/capability/auth checks, protected live acceptance, unsupported capability denial | final provider acceptance | Deferred input |
| `RSK-008` | Bank/gift provider lacks authoritative reserve/capture semantics | High | Critical | Validation never equals capture; manual review unless exact provider authority supports safe settlement | provider onboarding | Deferred input |
| `RSK-009` | IRR/Toman/crypto rounding mismatch causes incorrect settlement | Medium | Critical | Integer IRR, explicit units, fixed-precision crypto, snapshotted conversion/rate, boundary tests | financial/payment gate | Mitigated—monitor |
| `RSK-010` | Secret or sensitive identifier leaks through logs/evidence/backups/provider payloads | Medium | Critical | Central redaction, encryption/keyed lookup hashes, masked presentation, restricted evidence, negative leakage tests | every phase | Open |
| `RSK-011` | Compromised administrator executes sensitive financial/ownership/release action | Medium | Critical | Default deny, execution-time permission checks, replay-safe confirmation, re-auth/dual approval where required, immutable audit/alerts | sensitive-action gates | Partially mitigated |
| `RSK-012` | Redis loss becomes a correctness failure | Medium | High | Redis coordination is advisory; MariaDB uniqueness/transactions remain final barriers; retryable jobs and health handling | architecture/runtime | Mitigated—monitor |
| `RSK-013` | Malicious receipt/attachment exploits parser or storage | Medium | High | Private storage, MIME/size checks, random names, no execution, safe transformation/scanning hooks, authorized delivery | file-feature delivery | Open |
| `RSK-014` | Tampered update/backup gains code execution or corrupts state | Medium | Critical | Signed/checksummed manifests, trusted keys, authenticated encryption, compatibility checks, isolated restore validation | backup/update gate | Open |
| `RSK-015` | Atomic code rollback is incompatible with irreversible DB migration | Medium | Critical | Expand/contract migrations, compatibility metadata, pre-update backup, explicit rollback compatibility, restore rehearsal | every release | Open |
| `RSK-016` | Telegram delivery failure is mistaken for transaction failure | Medium | High | Commit business state first; separate retryable delivery/outbox; expose committed state independently | Order/Telegram integration | Open |
| `RSK-017` | Forged/replayed provider webhook changes financial state | Medium | Critical | Raw-body signature verification, timestamp/nonce where supported, unique event identity, idempotent async handling, status re-query | webhook provider gate | Open |
| `RSK-018` | OTP abuse causes cost, enumeration, or account takeover | High | High | Hashed OTP, bounded attempts, phone/user/IP/provider limits, cooldown/expiry, fixed responses, safe uncertain-send handling | identity regression | Mitigated—monitor |
| `RSK-019` | Exact-amount collision misattributes a card transfer | Medium | Critical | DB-unique active collision key, exact destination/amount/time matching, ambiguity to manual review | card-to-card gate | Open |
| `RSK-020` | One bank transaction/gift redemption funds multiple intents | Medium | Critical | Unique external consumption identity; settlement and consumption atomically bound; replay/conflict tests | card/gift payment gate | Open |
| `RSK-021` | PII retained too long or unavailable for legitimate/audit needs | Medium | High | Purpose-limited inventory/access, retention policy/jobs, legal hold, audited export/reveal, encrypted backups | production data | Deferred input |
| `RSK-022` | Backup exists but cannot meet recovery objective | Medium | Critical | Checksum/decrypt test, isolated restore rehearsal, measured RPO/RTO, post-restore reconciliation | operations/release | Deferred input |
| `RSK-023` | Broadcast traffic starves payment/provisioning jobs | Medium | High | Separate queue routing/budgets, priority isolation, rate limits, backlog alerts, load evidence | Telegram/load gate | Open |
| `RSK-024` | Vulnerable/abandoned dependency or license conflict enters release | Medium | High | Locked minimal dependencies, Composer audit, license policy, provenance/checksum review, update discipline | CI + release | Controlled |
| `RSK-025` | Telegram Bot API feature/limit changes break message lifecycle | Medium | Medium | Dated capability checks, graceful fallback, per-recipient outcomes, no unsupported promises | Telegram implementation/release | Open |
| `RSK-026` | CLI PHP and OpenLiteSpeed PHP runtimes diverge | High | High | Independently preflight version/extensions/ini/timezone/functions/limits/OPcache on target | install/deployment | Partially mitigated |
| `RSK-027` | Production credentials leak during onboarding/operations | Low | Critical | Never collect in Chat/repository; protected runtime input; no echo; restricted temporary handling | every live integration | Controlled |
| `RSK-028` | Unknown production volume invalidates queue/DB design | Medium | High | Instrument first; set workload/SLO targets; baseline and target-specific load tests before release | performance/release | Deferred input |
| `RSK-029` | Retention cleanup deletes reconciliation/dispute/audit evidence | Medium | High | Financial/audit evidence is immutable; retention differentiates content/evidence; legal hold overrides cleanup | retention/backup gate | Open |
| `RSK-030` | Generic REST mapping becomes arbitrary code execution | Medium | Critical | Declarative allowlisted paths/transforms only; schema validation; no expressions/templates/PHP/SQL/shell | Generic REST review | Open |

## Accepted-control interpretation

Existing accepted foundations may reduce a risk only for their exact bounded scope. Examples include wallet contention/append-only controls, fixed-precision pricing/USDT quote behavior, OTP abuse controls, authenticated Redis architecture, and mandatory dependency/license CI.

Those foundations do not automatically close later Order, provider, backup, Telegram, or production acceptance risk. Later work must retain regression evidence for earlier controls.

## Risk ownership by delivery gate

This mapping is stable ownership guidance, not live progress:

- Pricing/payments: `RSK-001`, `RSK-002`, `RSK-006`, `RSK-008`, `RSK-009`, `RSK-010`, `RSK-017`, `RSK-019`, `RSK-020`, `RSK-030`.
- Orders/provisioning/services: `RSK-001`, `RSK-002`, `RSK-005`, `RSK-016`.
- Telegram/content/support: `RSK-013`, `RSK-016`, `RSK-023`, `RSK-025`.
- Operations/backup/update: `RSK-014`, `RSK-015`, `RSK-022`, `RSK-026`, `RSK-029`.
- Release/production acceptance: all unresolved High/Critical risks, especially `RSK-007`, `RSK-021`, `RSK-024`, `RSK-027`, `RSK-028`.

## External and Owner decisions

| ID | Input needed | Safe engineering default | Must be resolved before |
|---|---|---|---|
| `DEC-001` | Real bank transaction API and its authority model | Fake provider + disabled Generic REST configuration | automatic card verification activation |
| `DEC-002` | Real gift-card provider/brand/region/value and reserve/capture semantics | Fake provider + manual fallback | automatic gift-card capture activation |
| `DEC-003` | High-value and dual-approval thresholds | Conservative deployment configuration | live sensitive financial operation |
| `DEC-004` | PII/media/provider-payload retention and legal obligations | Provisional retention rules; preserve immutable financial/audit evidence | production data collection |
| `DEC-005` | Target workload, RPO, RTO, maintenance window | Instrument and baseline without claiming production capacity | release certification |
| `DEC-006` | Installed Marzban/PasarGuard versions and protected credentials | Contracts/fakes/offline harness only | live provider/Target activation |
| `DEC-007` | Signing-key and backup-key custody | Separate protected keys, never repository/same backup | signed release / encrypted production backup |

## Review protocol

Review this register:

- at every phase gate;
- after any security, financial, provider, schema, restore, deployment, or release incident;
- when a new external integration or privileged operational surface is introduced;
- before final release acceptance.

A lifecycle change must be justified by accepted evidence or an explicit external decision. Dynamic task progress remains in GitHub; Git history preserves prior risk assessments.
