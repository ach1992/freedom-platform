# Authoritative Requirements

Baseline: Master Execution Prompt `1.0.0` (`2026-08-03`)  
Document status: `active`  
Implementation status: `in-progress` (selected foundation requirements only)

## Interpretation

The Master Execution Prompt remains the normative source. This ledger assigns durable IDs and concise acceptance statements. `MUST` is release-blocking, `SHOULD` requires an accepted rationale to omit, and `MAY` is optional. All catalogue requirements below are `MUST`. A requirement is complete only when its traceability row links approved design, code, automated tests, exact verification command/result, and retained evidence.

## Canonical functional and workflow requirements

| ID | Acceptance outcome |
|---|---|
| `ONB-001` | First `/start` upserts one Telegram identity, preserves Telegram ID, safely records referral payload, localizes the menu, and is update-idempotent. |
| `ONB-002` | Referral attribution is first-entry-only, never self-referential, locks after first successful purchase, and is audited. |
| `ONB-003` | Global/action membership gates support links, retry, and configured fail-open/fail-closed behavior. |
| `ONB-004` | Contact/OTP/either/both phone policies normalize Iranian numbers, enforce uniqueness, and support audited re-verification. |
| `ONB-005` | Blocked, suspended, limited, and maintenance policies safely deny protected actions while retaining configured support/service access. |
| `USR-001` | My Account safely displays profile, IDs, tier/type/status, masked phone, wallet buckets, referrals, and dates. |
| `USR-002` | Configurable `new/normal/loyal/vip` rules recalculate, support audited lock/override, and exclude ineligible orders. |
| `USR-003` | Authorized status/tag changes require confirmation, reason, and before/after audit. |
| `AGT-001` | One active cooperation request per customer is visible and duplicate-safe. |
| `AGT-002` | Authorized claim/approve/reject/release/suspend/restore transitions are audited; approval atomically assigns agent/pricing profiles. |
| `AGT-003` | Agent purchase uses snapshotted agent pricing and eligible gateways, retaining normal payment integrity and service ownership. |
| `AGT-004` | Bulk purchase has one parent and independently idempotent children; retry never recreates successful children. |
| `AGT-005` | Most-specific action/offering/server/product agent price wins, is snapshotted, and follows discount-combination policy. |
| `AGT-006` | Permission-scoped agent profile/report includes dates, counts, spending/sales, and purchased services. |
| `ACL-001` | Multiple roles and per-admin inherit/allow/deny overrides resolve with explicit deny precedence. |
| `ACL-002` | Every administrator action is authorized server-side regardless of menu visibility. |
| `ACL-003` | Sensitive financial, credential, ownership, restore, update, and batch actions use confirmation and policy-driven dual approval. |
| `CAT-001` | Localized categories can be created, edited, ordered, activated, and archived without losing history. |
| `CAT-002` | Products represent commercial identity; offerings hold server-specific price, duration, data, capacity, target, capabilities, and eligibility. |
| `CAT-003` | Service modes are typed while customer-facing labels remain editable. |
| `CAT-004` | Protocol/target configuration is typed and validated; selection appears only when enabled and supported. |
| `CAT-005` | Custom plans validate ranges/steps/usernames before payment and provisioning and snapshot every price component. |
| `CAT-006` | Trials are zero-cost order sources with configurable allowance, capacity, eligibility, identity/membership, fallback, and abuse controls. |
| `CAT-007` | A localized, ordered, policy-filtered client/guide catalogue supports audited add/edit/disable. |
| `CAT-008` | Health/capacity can stop/recover sales and use only compatible, pre-disclosed configured fallback targets. |
| `BUY-001` | Purchase executes policy → selection → quote/discount → eligible gateway → authoritative payment → provisioning → delivery. |
| `BUY-002` | Quotes contain immutable base, override, discount, final amount, currency, validity, and configuration snapshots. |
| `BUY-003` | Back, Cancel, timeout, repeated click, and resume behavior are deterministic. |
| `PAY-001` | Gateway eligibility evaluates state, account, tier/tags/history, amount/action/offering, identity, time/limits, override, and health precedence. |
| `PAY-002` | Controlled intents are allowed but only one captured settlement completes an order; browser returns never prove payment. |
| `PAY-003` | Duplicate external/internal events return the prior result without a second capture or provisioning effect. |
| `C2C-001` | Exact card amount adds configurable 100–999 Toman, is collision-free in scope, expires, and excludes the adjustment from refunds. |
| `C2C-002` | Private manual receipt review is permissioned, reasoned, reviewer-race-safe, and idempotent. |
| `C2C-003` | Fake and Generic REST bank providers support pull/signed webhook, normalization, cursor, deduplication, health, and circuit breaking. |
| `C2C-004` | Auto-match requires settled, destination, exact amount, time, unique external transaction, and one unambiguous eligible intent. |
| `C2C-005` | Mismatch/ambiguity/reversal/duplicate/stale/provider/risk cases enter review and scheduled reconciliation. |
| `GFT-001` | Type policy controls image/code input; normalized codes use keyed hashes and encrypted recoverable value only when required. |
| `GFT-002` | Private manual gift review is permissioned, reasoned, limited, and idempotently captures at most once. |
| `GFT-003` | Fake and Generic REST adapters separate validate, reserve, redeem/capture, release, status, health, and reconciliation. |
| `GFT-004` | Code/redemption value funds at most one payment; non-authoritative or mismatched results never auto-capture. |
| `USDT-001` | Direct USDT names BEP20 and locks address, rate source, raw/final rates, margin, exact decimal amount, and expiry. |
| `USDT-002` | Manual/Nobitex/Tetherland sources use priority/fallback, age/bounds/divergence guards, circuit breaker, and connection test. |
| `USDT-003` | Unique TXID plus optional evidence undergoes network/destination/amount/confirmation/time review and supports a future verifier adapter. |
| `IPG-001` | Zarinpal uses current REST request and authoritative verify with authority/amount matching, idempotency, capability-aware refund, and reconciliation. |
| `IPG-002` | NOWPayments verifies canonical IPN signature and authoritative status/identity/amount/currency with mismatch policy, expiry, idempotency, and reconciliation. |
| `WAL-001` | External wallet top-up uses a Payment Intent and posts exactly one balanced cash ledger transaction after capture. |
| `WAL-002` | Cash/promotional buckets use append-only balanced entries, holds/capture/release, snapshots, and reconciliation. |
| `WAL-003` | Transfer resolves a stable recipient, validates policy/limits/bucket/fee/status, confirms, and atomically posts both sides. |
| `WAL-004` | Partial/idempotent refunds follow method destination, never exceed refundable capture, and exclude card adjustment. |
| `WAL-005` | Balance correction requires permission, bucket/amount/reason/preview/confirmation, optional dual approval, and compensating entries. |
| `PRV-001` | Marzban/PasarGuard connections are encrypted, versioned, tested, health-checked, capability-discovered, and target-mapped. |
| `PRV-002` | Deterministic provisioning checks remote state before retry and verifies the result, producing at most one remote service per item. |
| `PRV-003` | Uncertain create timeout triggers discovery/reconciliation before any new create. |
| `SVC-001` | Owned-service list/search safely shows state, quota/usage, expiry, sync age, server/plan, and permitted actions. |
| `SVC-002` | Delivery supports policy-selected subscription/config links and QR with secure resend and secret-redacted operations. |
| `SVC-003` | Renewal/data/day/combined packages traverse normal quote/payment/provisioning. |
| `SVC-004` | Reset/log-clear/activation/suspension/refresh/rotation actions require both policy and adapter capability. |
| `SVC-005` | Plan/location/protocol change validates compatibility, price policy, remote result, and revised delivery. |
| `SVC-006` | Customer retirement warns/confirms, applies remote effect once, records non-refund policy, and preserves history. |
| `SVC-007` | Auto-renew uses one wallet hold/capture, configured package/price-change policy, and outcome notifications. |
| `SVC-008` | Import accepts only registered service domains, uses SSRF-safe parsing/known adapters, previews and attaches without fake payment. |
| `SVC-009` | Ownership transfer is explicit, authorized, validated, and audited; direct user-ID editing is forbidden. |
| `SVC-010` | Repair compares local/remote state and changes only allowlisted metadata with before/after evidence, never finance. |
| `SVC-011` | Authorized manual/complimentary single and batch services record explicit non-paid source. |
| `SVC-012` | Batch grants preview/filter, apply per-service idempotency, pause/resume/cancel, report progress, notify, and export results. |
| `SVC-013` | Global/per-service notifications are independently configured and sent once per service cycle with correct reset. |
| `SVC-014` | Authorized resend returns current details without rotating identifiers. |
| `PRO-001` | Discount rules support type/cap/minimum/time/limits/audience/scope, reserve/redeem/release, and explicit free-order permission. |
| `PRO-002` | Separate gift codes grant credit/service/discount and support secure single/batch generation, scope, expiry, disable, and audit. |
| `REF-001` | Referral attribution/reward pending-release/reversal/limits/anti-abuse/notifications are idempotent. |
| `SUP-001` | Customer creates a categorized, linked, attachment-safe ticket with unique tracking number. |
| `SUP-002` | Support lifecycle includes queue/claim/assignment/transfer/priority/status/reply/note/canned/search/close/reopen/rating. |
| `COM-001` | Permissioned direct customer messaging supports safe media modes/buttons, target preview/confirmation, idempotent attempts, and result audit. |
| `COM-002` | Broadcast offers audience estimate, preview, Owner test, immediate/scheduled run, pause/resume/cancel, progress, and one recipient state. |
| `COM-003` | Where Telegram permits, delivered messages support edit/buttons/pin/unpin/delete and failure-only retry with per-recipient result. |
| `CNT-001` | All visible copy uses validated localization/content keys with complete Persian, English fallback, placeholders, history, preview, and reset. |
| `CNT-002` | System menu configuration stays navigable; custom buttons execute only allowlisted safe actions/HTTPS destinations. |
| `CNT-003` | Button styles and premium emoji preview and degrade to normal emoji/default style without breaking navigation. |
| `CHN-001` | Multiple channel/group rules support all/any, action/audience/date scope, retry, tested bot access, and failure policy. |
| `ADM-001` | Permission-aware unified search covers users/orders/payments/services/TXIDs/submissions and masks unauthorized fields. |
| `ADM-002` | Telegram administration fully manages admin lifecycle, roles, permissions, overrides, hardened ownership transfer, and audit. |
| `REP-001` | Permission-aware reports cover all mandated commercial, financial, service, operational, support, and campaign measures with definitions. |
| `REP-002` | Date filters include specified relative/Persian/custom ranges and prior-period comparison with UTC-safe Tehran display. |
| `REP-003` | Reports render in Telegram, export CSV/XLSX, send to configured channel, and schedule under permissions. |
| `OPS-001` | Secret-safe structured logs, correlation, severity, durable/deduplicated alerts, Owner/channel delivery, and immutable audit operate reliably. |
| `OPS-002` | Operations Center exposes mandated pending/failure/reconciliation/integration/job/alert/heartbeat conditions and safe actions. |
| `OPS-003` | One Cron drives Scheduler; prioritized Redis queues, supervised workers, Outbox, locks, heartbeats, bounded retry, and dead-letter paths operate. |
| `BAK-001` | Configurable 10-minute DB, daily full, and pre-update backups are consistent, encrypted, checksummed, retained, and optionally split ≤45 MB to Telegram with manifest. |
| `BAK-002` | Restore authorizes, preflights/dry-runs, validates manifest/checksum/key/compatibility, preserves current state, restores DB/private files, and smoke/reconciles. |
| `INS-001` | One-time HTTPS installer validates both runtimes and all dependencies/integrations, writes secrets safely, seeds/migrates, configures webhook/Owner, reports, and locks. |
| `UPD-001` | Updater authenticates, verifies package/compatibility, drains safely, backs up/stages/migrates/tests/switches atomically, verifies, reports, and safely rolls back. |
| `SEC-001` | Secret/TLS/webhook, SSRF/upload/replay/CSRF/authz/injection/rate-limit protections are implemented and tested. |
| `QUA-001` | Every requirement traces to reviewed design/code/automated tests/command/result/evidence; mandatory financial/security/restore gates block closure. |

## Additional architecture, data, operational, security, and quality requirements

These stable IDs cover normative requirements outside the §36 catalogue. They do not weaken or duplicate the canonical IDs; they make non-functional scope independently traceable.

| ID | Requirement | Source | Priority | Status |
|---|---|---|---|---|
| `ARCH-001` | Use Laravel `13.x`, PHP 8.4, PSR-4, `strict_types`, PSR-12, typed code, dependency injection, and locked maintained dependencies with acceptable licenses. | §§2.2, 4.4–4.5 | MUST | `not-started` |
| `ARCH-002` | Enforce modular-monolith boundaries: Telegram/HTTP call Application Services; integrations use contracts/adapters; cross-module writes are explicit and transactional. | §§4.1–4.3 | MUST | `not-started` |
| `ARCH-003` | Use typed state machines and a centralized error taxonomy; forbid arbitrary status writes and swallowed `Throwable`. | §§4.2, 9 | MUST | `not-started` |
| `ARCH-004` | Use Transactional Outbox for committed external effects and injectable clock/random/HTTP dependencies. | §§4.2, 4.4, 24.3 | MUST | `not-started` |
| `DAT-001` | Store timestamps in UTC and render business time in `Asia/Tehran`, supporting Jalali formatting without altering stored time. | §§2.1, 5.1 | MUST | `not-started` |
| `DAT-002` | Store fiat as integer IRR, display explicit Toman conversion, and use fixed-precision decimal for crypto; monetary float is forbidden. | §5.1 | MUST | `not-started` |
| `DAT-003` | Implement mandatory schema concepts, foreign keys, uniqueness/idempotency constraints, lookup indexes, and enforced relational integrity strategy. | §28 | MUST | `not-started` |
| `DAT-004` | Financial and audit records are immutable/non-deletable; soft delete is used only for explicit business semantics. | §§14, 23.4, 28.12 | MUST | `not-started` |
| `RUN-001` | Target Ubuntu 22.04/aaPanel/OpenLiteSpeed with two separately preflighted PHP runtimes, MariaDB, authenticated Redis, and documented ownership. | §§2.1–2.3 | MUST | `not-started` |
| `RUN-002` | Use atomic versioned releases/shared storage; expose only `current/public`; never expose project root. | §2.4 | MUST | `not-started` |
| `RUN-003` | Use the exact single Scheduler Cron and supervised workers rather than per-task Cron entries. | §§2.5, 24 | MUST | `not-started` |
| `RUN-004` | Every scheduled task has locking, overlap prevention, timeout, idempotency, history, metrics, bounded retry, and dead-letter/manual review. | §24.2 | MUST | `not-started` |
| `RUN-005` | Backup export never exposes a password in process arguments; plaintext temporaries are protected and reliably removed after verified compression/encryption. | §§25.2–25.3 | MUST | `not-started` |
| `RUN-006` | Release packages include source, lockfile, migrations, manifest, compatibility metadata, checksums/signature, and release notes without secrets. | §§27.1, 34 | MUST | `not-started` |
| `SEC-002` | Default-deny, least-privilege authorization binds Telegram actor/chat, invalidates permission cache, and uses signed/replay-safe sensitive actions. | §29.2 | MUST | `not-started` |
| `SEC-003` | Encrypt secrets/sensitive identity/payment data; use keyed hashes for lookup; mask by default; audit narrowly scoped reveal. | §§5.6, 11–12, 29.2–29.3 | MUST | `not-started` |
| `SEC-004` | All configurable outbound endpoints use HTTPS, DNS/IP/redirect revalidation, reserved-range blocking, allowlists where possible, and bounded response/time. | §§11.6, 12.4, 29.4 | MUST | `not-started` |
| `SEC-005` | TLS certificate and hostname verification cannot be disabled; private systems require managed CA/pin and rotation. | §§8.3, 29.5 | MUST | `not-started` |
| `SEC-006` | Uploaded media is private, MIME-inspected, allowlisted, size-bounded, randomly named, non-executable, retained by policy, and access-controlled. | §17.6 | MUST | `not-started` |
| `SEC-007` | Browser installer/updater use HTTPS, CSRF/session protections, rate limits, restrictive filesystem permissions, security headers, and no arbitrary command execution. | §§26.3, 29.2 | MUST | `not-started` |
| `SEC-008` | Secrets are accepted only via protected channels, never returned in full, support rotation, and are excluded/redacted from code, evidence, logs, backups, and reports. | §§23, 29.3, 32.1 | MUST | `not-started` |
| `SEC-009` | Verify provider/Telegram webhook authenticity before business parsing, persist event idempotently, acknowledge quickly, and process asynchronously. | §§11.9, 13, 17.1 | MUST | `not-started` |
| `SEC-010` | Signed packages and authenticated-encryption backups detect tampering; restore/update fail closed on integrity or compatibility failure. | §§25–27, 29 | MUST | `not-started` |
| `LOC-001` | Ship complete Persian defaults and English fallback for every mandated namespace; all placeholders, parse modes, lengths, and contexts are documented/validated. | §18 | MUST | `not-started` |
| `LOC-002` | Persian terminology is consistent with the glossary; amounts explicitly label `تومان` for display and never silently reinterpret IRR. | §§5.1, 17–18 | MUST | `not-started` |
| `INT-001` | Re-verify official documentation and target versions before each integration; store a dated, redacted contract note and contract-test evidence. | §35 | MUST | `not-started` |
| `INT-002` | Every provider exposes capabilities/health, timeouts, bounded retry, circuit breaking, normalized statuses, reconciliation, and a deterministic fake. | §§8, 10–13 | MUST | `not-started` |
| `QUA-002` | Configure Pint, strict-practical PHPStan/Larastan, PHPUnit/Pest, Composer audit, license report, MariaDB migrations, secret scanning, and forbidden-pattern architecture gates. | §4.5 | MUST | `not-started` |
| `QUA-003` | Unit tests cover all calculations, normalization, policy/state resolution, security transforms, and content validation listed in §30.2. | §30.2 | MUST | `not-started` |
| `QUA-004` | Real MariaDB/Redis integration and concurrency tests cover schema, ledger, idempotency, races, Outbox, queues, encryption, and query plans. | §§30.1, 30.3 | MUST | `not-started` |
| `QUA-005` | Apply the common provider matrix to every payment method, proving no provisioning before authoritative capture. | §30.4 | MUST | `not-started` |
| `QUA-006` | Execute complete automatic/manual card and automatic/manual gift-card matrices, including race, uncertain-result, SSRF, and secret-redaction cases. | §§30.5–30.7 | MUST | `not-started` |
| `QUA-007` | Execute provisioning, wallet/concurrency, Telegram E2E, and full functional workflow regression matrices. | §§30.8–30.10, 30.15 | MUST | `not-started` |
| `QUA-008` | Execute security tests for authz/IDOR, forgery/replay, CSRF/session, injection, SSRF/rebinding, upload/path, TLS, secrets/logs, package/backup tamper, abuse, and cache invalidation. | §30.11 | MUST | `not-started` |
| `QUA-009` | Establish owner-approved load targets; before then run documented baseline performance, backlog recovery, and Redis/dependency degradation tests without claiming capacity certification. | §30.12 | MUST | `not-started` |
| `QUA-010` | Execute failure/chaos tests for Telegram/Redis/worker/DB/panel/provider/disk/backup/deployment interruptions. | §30.13 | MUST | `not-started` |
| `QUA-011` | Rehearse clean install, replay denial, update/failure/rollback, encrypted split backup, complete restore, smoke, and financial reconciliation in a target-like environment. | §30.14 | MUST | `not-started` |
| `QUA-012` | Block release on any static gate listed in §30.16, unresolved Critical/High defect/security finding, or failed mandatory invariant. | §§0.1, 30.16, 37 | MUST | `not-started` |
| `QUA-013` | Every test claim records command, environment, result, and evidence path; fake/contract/sandbox/live evidence types remain distinguishable. | §§0.1, 30.1, 34.3 | MUST | `not-started` |

## Global financial and authorization invariants

1. No paid service is provisioned before authoritative capture; trial, gift, and admin grants use explicit non-paid sources.
2. One provider transaction or redeemable value cannot settle two payments.
3. One paid order item produces at most one active remote identity.
4. A duplicate update, callback, webhook, job, click, review, or retry produces the original result, not a new effect.
5. Financial transitions are transactional and protected by database uniqueness; Redis locks are coordination only.
6. Failed provisioning never erases or recollects a successful payment; it enters retry/review.
7. Ledger transactions balance and remain append-only; corrections are compensating entries.
8. Authorization is checked on execution with default deny; UI visibility is never proof of permission.
9. Uncertain external outcomes are reconciled before retrying a potentially irreversible operation.
10. Secret exposure, unexplained ledger mismatch, duplicate service risk, and payment reversal after provisioning are Critical incidents.
