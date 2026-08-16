# Authoritative Requirement IDs

The normative Version `1.0.0` requirements are defined in `docs/specification/master-execution-prompt.md`. This file provides stable IDs for planning, implementation, tests, review, and release traceability. It intentionally contains **no implementation status**.

A requirement is not complete because a class, migration, document, fake, or test exists. Completion is determined from the applicable GitHub Issue/PR, exact-head CI, review, and final release acceptance.

The two explicit Owner-approved Version 1 provider clarifications below are also reflected in the master specification. They remain repeated here to keep the stable requirement IDs unambiguous; no other master-specification requirement is changed.

## Version 1 provider clarifications

- `USDT-002`: the supported rate-source set is Manual, Nobitex public USDT/RLS market data, and Wallex public `USDTTMN` spot-market data. Wallex replaces the historically named Tetherland source. Runtime selection remains deterministic with freshness, sanity, divergence, circuit-breaker, and explicit Manual fallback policy. The Manual IRR-per-USDT rate is a protected managed product setting with versioned/audited history; deployment configuration is bootstrap fallback only when no managed setting exists. The common provider contract remains extensible for future rate sources.
- `IPG-002`: NOWPayments uses the same selected IRR-per-USDT rate authority as direct USDT pricing (`Nobitex -> Wallex -> Manual`) as the explicit Version 1 business pricing proxy for required `price_currency=usd`. This is a product pricing policy, not a claim that USD and USDT are economically identical. The selected source, exact rate, provider evidence identity, pricing-policy identity, derived USD `price_amount`, pay currency, and rounding policy are snapshotted immutably per payment. Existing payments are never reconstructed from a later/current rate, and no separate USD/IRR manual rate is permitted in Version 1.

## Functional requirement IDs

- Onboarding: `ONB-001`, `ONB-002`, `ONB-003`, `ONB-004`, `ONB-005`
- Users/customers: `USR-001`, `USR-002`, `USR-003`
- Agents/resellers: `AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-005`, `AGT-006`
- Access control: `ACL-001`, `ACL-002`, `ACL-003`
- Catalog/offers: `CAT-001`, `CAT-002`, `CAT-003`, `CAT-004`, `CAT-005`, `CAT-006`, `CAT-007`, `CAT-008`
- Purchase/quote: `BUY-001`, `BUY-002`, `BUY-003`
- Payment authority/routing: `PAY-001`, `PAY-002`, `PAY-003`
- Card-to-card: `C2C-001`, `C2C-002`, `C2C-003`, `C2C-004`, `C2C-005`
- Gift-card payments: `GFT-001`, `GFT-002`, `GFT-003`, `GFT-004`
- USDT BEP20: `USDT-001`, `USDT-002`, `USDT-003`
- Internet payment gateways: `IPG-001`, `IPG-002`
- Wallet/ledger: `WAL-001`, `WAL-002`, `WAL-003`, `WAL-004`, `WAL-005`
- Promotions: `PRO-001`, `PRO-002`
- Referrals: `REF-001`
- Panel/provisioning: `PRV-001`, `PRV-002`, `PRV-003`
- Service lifecycle: `SVC-001`, `SVC-002`, `SVC-003`, `SVC-004`, `SVC-005`, `SVC-006`, `SVC-007`, `SVC-008`, `SVC-009`, `SVC-010`, `SVC-011`, `SVC-012`, `SVC-013`, `SVC-014`
- Support: `SUP-001`, `SUP-002`
- Messaging/broadcast: `COM-001`, `COM-002`, `COM-003`
- Content/localization: `CNT-001`, `CNT-002`, `CNT-003`
- Channel/membership rules: `CHN-001`
- Administration/search: `ADM-001`, `ADM-002`
- Reporting: `REP-001`, `REP-002`, `REP-003`
- Operations/runtime: `OPS-001`, `OPS-002`, `OPS-003`
- Backup/restore: `BAK-001`, `BAK-002`
- Installer: `INS-001`
- Update/rollback: `UPD-001`
- Security umbrella: `SEC-001`
- Quality/traceability umbrella: `QUA-001`

## Cross-cutting requirement IDs

These IDs make normative architecture, data, runtime, security, localization, integration, and quality requirements independently traceable without storing task progress here. The concise meanings and master-spec source sections below restore the stable semantics that existed before project-control cleanup; the master specification remains normative if wording here is incomplete or ambiguous.

| ID | Requirement | Master source |
|---|---|---|
| `ARCH-001` | Use Laravel `13.x`, PHP 8.4, PSR-4, `strict_types`, PSR-12, typed code, dependency injection, and locked maintained dependencies with acceptable licenses. | §§2.2, 4.4–4.5 |
| `ARCH-002` | Enforce modular-monolith boundaries: Telegram/HTTP call Application Services; integrations use contracts/adapters; cross-module writes are explicit and transactional. | §§4.1–4.3 |
| `ARCH-003` | Use typed state machines and a centralized error taxonomy; forbid arbitrary status writes and swallowed `Throwable`. | §§4.2, 9 |
| `ARCH-004` | Use Transactional Outbox for committed external effects and injectable clock/random/HTTP dependencies. | §§4.2, 4.4, 24.3 |
| `DAT-001` | Store timestamps in UTC and render business time in `Asia/Tehran`, supporting Jalali formatting without altering stored time. | §§2.1, 5.1 |
| `DAT-002` | Store fiat as integer IRR, display explicit Toman conversion, and use fixed-precision decimal for crypto; monetary float is forbidden. | §5.1 |
| `DAT-003` | Implement mandatory schema concepts, foreign keys, database uniqueness and replay-safety constraints, lookup indexes, and an enforced relational-integrity strategy. | §28 |
| `DAT-004` | Financial and audit records are immutable/non-deletable; soft delete is used only for explicit business semantics. | §§14, 23.4, 28.12 |
| `RUN-001` | Target Ubuntu 22.04/aaPanel/OpenLiteSpeed with two separately preflighted PHP runtimes, MariaDB, authenticated Redis, and documented ownership. | §§2.1–2.3 |
| `RUN-002` | Use atomic versioned releases/shared storage; expose only `current/public`; never expose project root. | §2.4 |
| `RUN-003` | Use the exact single Scheduler Cron and supervised workers rather than per-task Cron entries. | §§2.5, 24 |
| `RUN-004` | Every scheduled task has locking, overlap prevention, timeout, idempotency, history, metrics, bounded retry, and dead-letter/manual review. | §24.2 |
| `RUN-005` | Backup export never exposes a password in process arguments; plaintext temporaries are protected and reliably removed after verified compression/encryption. | §§25.2–25.3 |
| `RUN-006` | Release packages include source, lockfile, migrations, manifest, compatibility metadata, checksums/signature, and release notes without secrets. | §§27.1, 34 |
| `SEC-002` | Default-deny, least-privilege authorization binds Telegram actor/chat, invalidates permission cache, and uses signed/replay-safe sensitive actions. | §29.2 |
| `SEC-003` | Encrypt secrets/sensitive identity/payment data; use keyed hashes for lookup; mask by default; audit narrowly scoped reveal. | §§5.6, 11–12, 29.2–29.3 |
| `SEC-004` | All configurable outbound endpoints use HTTPS, DNS/IP/redirect revalidation, reserved-range blocking, allowlists where possible, and bounded response/time. | §§11.6, 12.4, 29.4 |
| `SEC-005` | TLS certificate and hostname verification cannot be disabled; private systems require managed CA/pin and rotation. | §§8.3, 29.5 |
| `SEC-006` | Uploaded media is private, MIME-inspected, allowlisted, size-bounded, randomly named, non-executable, retained by policy, and access-controlled. | §17.6 |
| `SEC-007` | Browser installer/updater use HTTPS, CSRF/session protections, rate limits, restrictive filesystem permissions, security headers, and no arbitrary command execution. | §§26.3, 29.2 |
| `SEC-008` | Secrets are accepted only via protected channels, never returned in full, support rotation, and are excluded/redacted from code, evidence, logs, backups, and reports. | §§23, 29.3, 32.1 |
| `SEC-009` | Verify provider/Telegram webhook authenticity before business parsing, persist events idempotently, acknowledge quickly, and process asynchronously. | §§11.9, 13, 17.1 |
| `SEC-010` | Signed packages and authenticated-encryption backups detect tampering; restore/update fail closed on integrity or compatibility failure. | §§25–27, 29 |
| `LOC-001` | Ship complete Persian defaults and English fallback for every mandated namespace; all placeholders, parse modes, lengths, and contexts are documented/validated. | §18 |
| `LOC-002` | Persian terminology is consistent with the glossary; amounts explicitly label `تومان` for display and never silently reinterpret IRR. | §§5.1, 17–18 |
| `INT-001` | Re-verify official documentation and target versions before each integration; store a dated, redacted contract note and contract-test evidence. | §35 |
| `INT-002` | Every provider exposes capabilities/health, timeouts, bounded retry, circuit breaking, normalized statuses, reconciliation, and a deterministic fake. | §§8, 10–13 |
| `QUA-002` | Configure Pint, strict-practical PHPStan/Larastan, PHPUnit/Pest, Composer audit, license report, MariaDB migrations, secret scanning, and forbidden-pattern architecture gates. | §4.5 |
| `QUA-003` | Unit tests cover all calculations, normalization, policy/state resolution, security transforms, and content validation listed in §30.2. | §30.2 |
| `QUA-004` | Real MariaDB/Redis integration and concurrency tests cover schema, ledger, idempotency, races, Outbox, queues, encryption, and query plans. | §§30.1, 30.3 |
| `QUA-005` | Apply the common provider matrix to every payment method, proving no provisioning before authoritative capture. | §30.4 |
| `QUA-006` | Execute complete automatic/manual card and automatic/manual gift-card matrices, including race, uncertain-result, SSRF, and secret-redaction cases. | §§30.5–30.7 |
| `QUA-007` | Execute provisioning, wallet/concurrency, Telegram E2E, and full functional workflow regression matrices. | §§30.8–30.10, 30.15 |
| `QUA-008` | Execute security tests for authz/IDOR, forgery/replay, CSRF/session, injection, SSRF/rebinding, upload/path, TLS, secrets/logs, package/backup tamper, abuse, and cache invalidation. | §30.11 |
| `QUA-009` | Establish Owner-approved load targets; before then run documented baseline performance, backlog recovery, and Redis/dependency degradation tests without claiming capacity certification. | §30.12 |
| `QUA-010` | Execute failure/chaos tests for Telegram/Redis/worker/DB/panel/provider/disk/backup/deployment interruptions. | §30.13 |
| `QUA-011` | Rehearse clean install, replay denial, update/failure/rollback, encrypted split backup, complete restore, smoke, and financial reconciliation in a target-like environment. | §30.14 |
| `QUA-012` | Block release on any static gate listed in §30.16, unresolved Critical/High defect/security finding, or failed mandatory invariant. | §§0.1, 30.16, 37 |
| `QUA-013` | Every test claim records command, environment, result, and evidence path; fake/contract/sandbox/live evidence types remain distinguishable. | §§0.1, 30.1, 34.3 |

## Global invariants

1. No paid service is provisioned before authoritative capture.
2. One external transaction or redeemable value cannot settle multiple payments.
3. One paid order item produces at most one active remote identity.
4. Duplicate updates, callbacks, jobs, clicks, reviews, or retries do not create a second effect.
5. MariaDB transactions, locks, uniqueness, and immutable history remain final correctness barriers; Redis is coordination only.
6. Financial records are append-only; corrections and reversals are compensating effects.
7. Authorization is checked at execution time with default deny.
8. Uncertain external outcomes are reconciled before retrying an irreversible mutation.
9. Browser/customer assertions never prove payment capture.
10. Secrets, full sensitive data, and raw protected provider payloads never enter ordinary logs, GitHub discussion, or repository evidence.

## Usage

Issues and PRs should reference only the requirement IDs they actually own. The Issue defines the bounded acceptance criteria for that increment; the master specification remains authoritative if an Issue is incomplete or ambiguous, except for an explicit durable clarification recorded in this file.

Do not add status columns, completion percentages, current SHAs, active branch names, or CI run IDs to this file.