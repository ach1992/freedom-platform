# Authoritative Requirement IDs

The normative Version `1.0.0` requirements are defined in `docs/specification/master-execution-prompt.md`. This file provides stable IDs for planning, implementation, tests, review, and release traceability. It intentionally contains **no implementation status**.

A requirement is not complete because a class, migration, document, fake, or test exists. Completion is determined from the applicable GitHub Issue/PR, exact-head CI, review, and final release acceptance.

## Functional requirement IDs

- Onboarding: `ONB-001`–`ONB-005`
- Users/customers: `USR-001`–`USR-003`
- Agents/resellers: `AGT-001`–`AGT-006`
- Access control: `ACL-001`–`ACL-003`
- Catalog/offers: `CAT-001`–`CAT-008`
- Purchase/quote: `BUY-001`–`BUY-003`
- Payment authority/routing: `PAY-001`–`PAY-003`
- Card-to-card: `C2C-001`–`C2C-005`
- Gift-card payments: `GFT-001`–`GFT-004`
- USDT BEP20: `USDT-001`–`USDT-003`
- Internet payment gateways: `IPG-001`–`IPG-002`
- Wallet/ledger: `WAL-001`–`WAL-005`
- Promotions: `PRO-001`–`PRO-002`
- Referrals: `REF-001`
- Panel/provisioning: `PRV-001`–`PRV-003`
- Service lifecycle: `SVC-001`–`SVC-014`
- Support: `SUP-001`–`SUP-002`
- Messaging/broadcast: `COM-001`–`COM-003`
- Content/localization: `CNT-001`–`CNT-003`
- Channel/membership rules: `CHN-001`
- Administration/search: `ADM-001`–`ADM-002`
- Reporting: `REP-001`–`REP-003`
- Operations/runtime: `OPS-001`–`OPS-003`
- Backup/restore: `BAK-001`–`BAK-002`
- Installer: `INS-001`
- Update/rollback: `UPD-001`
- Security umbrella: `SEC-001`
- Quality/traceability umbrella: `QUA-001`

## Cross-cutting requirement IDs

These IDs make normative architecture, data, runtime, security, localization, integration, and quality requirements independently traceable without duplicating their full wording here.

- Architecture: `ARCH-001`–`ARCH-004`
- Data/integrity: `DAT-001`–`DAT-004`
- Runtime/deployment: `RUN-001`–`RUN-006`
- Security: `SEC-002`–`SEC-010`
- Localization: `LOC-001`–`LOC-002`
- Integration/provider discipline: `INT-001`–`INT-002`
- Quality/testing: `QUA-002`–`QUA-013`

## Global invariants

These invariants apply across all requirement IDs:

1. No paid service is provisioned before authoritative capture.
2. One external transaction/redeemable value cannot settle multiple payments.
3. One paid order item produces at most one active remote identity.
4. Duplicate updates, callbacks, jobs, clicks, reviews, or retries do not create a second effect.
5. MariaDB transactions, locks, uniqueness, and immutable history remain final correctness barriers; Redis is coordination only.
6. Financial records are append-only; corrections and reversals are compensating effects.
7. Authorization is checked at execution time with default deny.
8. Uncertain external outcomes are reconciled before retrying an irreversible mutation.
9. Browser/customer assertions never prove payment capture.
10. Secrets, full sensitive data, and raw protected provider payloads never enter ordinary logs, GitHub discussion, or repository evidence.

## Usage

Issues and PRs should reference only the requirement IDs they actually own. The Issue defines the bounded acceptance criteria for that increment; the master specification remains authoritative if an Issue is incomplete or ambiguous.

Do not add status columns, completion percentages, current SHAs, active branch names, or CI run IDs to this file.