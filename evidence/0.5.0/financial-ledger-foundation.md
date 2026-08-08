# Phase 0.5 Financial Ledger Foundation Evidence

**Status:** implementation verified; bounded foundation only.  
**Phase:** `0.5.0 — Ledger, Pricing, Promotions and Payment Providers`.  
**Authoritative Issue:** `#8`.  
**Implementation head:** `259e29e6f93c2b36cd36c2f40bf669789eaab62f`.

## Accepted scope

This increment establishes the database and application posting foundation required by later wallet/payment work. It proves only the following bounded behavior:

- monetary values at this boundary are integer IRR through `IrrMoney`; negative values and arithmetic underflow/overflow are rejected;
- ledger accounts support system accounts plus one `cash` and one `promotional` liability bucket per user, with IRR-only and owner/bucket relational constraints;
- ledger transactions and entries are posted atomically through `LedgerPostingService`;
- each finalized transaction contains at least two entries and equal positive debit/credit totals;
- account rows used by a post are locked in deterministic numeric order and must exist, be active and use IRR;
- each logical command has a unique command key and canonical payload hash;
- exact replay returns the recorded finalized result without a second transaction or entry effect;
- conflicting reuse of a command key with a different payload fails closed and does not replace the first result;
- an incomplete matching command is not treated as success and requires reconciliation;
- database triggers independently reject unbalanced finalization, mutation/deletion of entries, mutation/deletion of finalized transaction identity, and mutation/deletion of account identity;
- the migration rollback path uses a fixed allowlisted set of trigger-drop statements and contains no raw SQL string concatenation.

## Requirement mapping

This increment contributes to, but does **not** complete, the following release requirements:

- `WAL-002` — accepted here only for cash/promotional bucket schema, balanced append-only posting and replay/conflict foundation. Holds, capture/release, balance snapshots and reconciliation remain open.
- `DAT-002` — integer IRR is enforced for this ledger boundary; project-wide fiat/crypto representation remains broader scope.
- `DAT-003` — ledger foreign keys, uniqueness, checks, indexes and relational constraints are executable; the project-wide mandatory schema requirement remains broader scope.
- `DAT-004` — ledger financial records have database-enforced immutability/non-deletion semantics; the project-wide financial/audit requirement remains broader scope.
- `QUA-001` — this bounded implementation has code, automated tests, exact-head CI and retained evidence; full Phase 0.5 traceability remains open.

`WAL-001`, `WAL-003`, `WAL-004`, and `WAL-005` are not claimed by this increment. Payment Intent top-up settlement, transfer, refund, balance correction, holds, capture/release and reconciliation remain later bounded work.

## Implementation surfaces

- `app/Modules/Wallet/Domain/IrrMoney.php`
- `app/Modules/Wallet/Domain/LedgerDirection.php`
- `app/Modules/Wallet/Application/LedgerEntryDraft.php`
- `app/Modules/Wallet/Application/LedgerPostingReceipt.php`
- `app/Modules/Wallet/Application/LedgerPostingService.php`
- `database/migrations/2026_08_08_002300_create_financial_ledger_foundation.php`

## Executable verification

Primary automated coverage:

- `tests/Unit/Modules/Wallet/IrrMoneyTest.php`
- `tests/Feature/FinancialLedgerFoundationTest.php`

The feature suite proves:

- balanced finalization and exact replay;
- conflicting replay leaves the original effect unchanged;
- unbalanced and inactive-account attempts have no financial effect;
- direct database bypass cannot finalize an unbalanced transaction;
- entries, finalized transactions and account identity are immutable/non-deletable;
- wallet cash/promotional account bucket constraints are enforced by MariaDB.

## Exact implementation CI

GitHub Actions run `31232006308` / run `#1037` on exact implementation head `259e29e6f93c2b36cd36c2f40bf669789eaab62f`:

- Repository preflight / planning / project-control verification — **success**;
- Secret scan — **success**;
- PHP static quality: Pint, PHPStan/Larastan and repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **326 tests, 1777 assertions, success**.

Retained test artifact:

- name: `test-evidence-31232006308`;
- artifact ID: `9014138405`;
- uploader SHA-256: `cfda302c54c7089dbb15eb0c83a546f63bd37a142c99e5919b8408dfa85b7610`;
- independently calculated SHA-256: `cfda302c54c7089dbb15eb0c83a546f63bd37a142c99e5919b8408dfa85b7610`.

Independent artifact inspection observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The bounded secret-pattern scan found no PasarGuard API-key marker, protected PasarGuard secret variable, private-key marker, or Bearer authorization material in the artifact.

## Safety and boundary notes

- This is not evidence that wallet available balance, holds, capture/release, transfer, refund, correction or reconciliation are complete.
- It does not create a Payment Intent or prove any external payment provider.
- It does not create an Order and cannot authorize provisioning.
- It does not weaken the carried Phase 0.4 provider gates: real PasarGuard mutation capabilities/Targets remain fail closed until live acceptance, and Marzban live acceptance remains deferred to final release acceptance.
- No live provider credential or sensitive provider artifact is contained in this evidence.

## Evidence-head gate

This document and its traceability companion require mandatory CI on the exact evidence-head commit before this bounded foundation is evidence-complete.
