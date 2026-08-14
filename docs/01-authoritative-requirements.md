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

These IDs make normative architecture, data, runtime, security, localization, integration, and quality requirements independently traceable without storing task progress here.

- Architecture: `ARCH-001`, `ARCH-002`, `ARCH-003`, `ARCH-004`
- Data/integrity: `DAT-001`, `DAT-002`, `DAT-003`, `DAT-004`
- Runtime/deployment: `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `RUN-005`, `RUN-006`
- Security: `SEC-002`, `SEC-003`, `SEC-004`, `SEC-005`, `SEC-006`, `SEC-007`, `SEC-008`, `SEC-009`, `SEC-010`
- Localization: `LOC-001`, `LOC-002`
- Integration/provider discipline: `INT-001`, `INT-002`
- Quality/testing: `QUA-002`, `QUA-003`, `QUA-004`, `QUA-005`, `QUA-006`, `QUA-007`, `QUA-008`, `QUA-009`, `QUA-010`, `QUA-011`, `QUA-012`, `QUA-013`

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