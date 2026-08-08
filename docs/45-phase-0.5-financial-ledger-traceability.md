# Phase 0.5 Financial Ledger Foundation Traceability

**Scope:** bounded balanced-ledger posting foundation only.  
**Authoritative phase tracker:** Issue `#8`.  
**Implementation boundary:** `259e29e6f93c2b36cd36c2f40bf669789eaab62f`.  
**Implementation CI:** `31232006308` / `#1037` — success, 326 tests / 1777 assertions.  
**Evidence:** `evidence/0.5.0/financial-ledger-foundation.md`.

## Requirement-to-proof map

| Requirement | Foundation status | Design / implementation | Automated proof | Remaining scope |
|---|---|---|---|---|
| `WAL-002` | partial, bounded foundation accepted after evidence-head CI | `ledger_accounts`, `ledger_transactions`, `ledger_entries`; `LedgerPostingService`; cash/promotional bucket constraints; balanced DB finalization guards | `FinancialLedgerFoundationTest` balanced posting/replay/conflict, no-effect rejection, DB bypass rejection, immutability, bucket constraints | holds, capture/release, balance snapshots and reconciliation |
| `DAT-002` | satisfied for this ledger boundary only | `IrrMoney`; integer `*_irr` columns; positive/non-negative checks; no monetary float | `IrrMoneyTest`; ledger feature tests | project-wide fiat display/Toman conversion and crypto fixed precision remain outside this increment |
| `DAT-003` | satisfied for this ledger schema only | foreign keys, unique command key, owner/bucket uniqueness, sequence uniqueness, checks/indexes, deterministic account locks | migration-backed MariaDB feature tests | broader project schema remains governed by later increments |
| `DAT-004` | satisfied for these ledger records | entry update/delete guards; finalized transaction identity update/delete guards; account identity update/delete guards | `FinancialLedgerFoundationTest::test_finalized_transactions_entries_and_account_identity_are_immutable` | other financial/audit record families remain separate |
| `QUA-001` | satisfied for this bounded implementation after evidence-head CI | requirement annotations, production code, tests, exact-head CI, retained artifact and independent digest | mandatory CI #1037 | full Phase 0.5 closure traceability remains open |

## Safety properties proven

### Balanced posting

`LedgerPostingService` rejects fewer than two entries, zero/unbalanced totals, missing accounts, inactive accounts and non-IRR accounts before final success. MariaDB independently recomputes debit, credit and entry count before allowing finalization.

### Replay and conflict

`ledger_transactions.command_key` is unique. The application computes a canonical payload hash from transaction type, source identity and ledger entries. An exact matching finalized command is replayed without adding another transaction or entries. Same-key/different-payload reuse throws a conflict and preserves the original effect. A matching but unfinished command is not returned as success and requires reconciliation.

### Immutability

Database triggers, not only service conventions, prevent mutation/deletion of entries, transaction identity after finalization, and account identity. Corrections/refunds must therefore be represented by later compensating-entry workflows rather than rewriting accepted history.

### Concurrency foundation

The posting path runs in a database transaction, locks referenced accounts in sorted ID order and uses the unique command key as the final duplicate-effect barrier. The service includes a unique-race recovery path that may return only a finalized exact payload match. Dedicated multi-process wallet hold/capture concurrency scenarios are intentionally still open with the later `WAL-002` work.

## Explicit non-claims

This traceability row does not claim completion of:

- `WAL-001` external wallet top-up through Payment Intent;
- the hold/capture/release/snapshot/reconciliation portion of `WAL-002`;
- `WAL-003` transfer;
- `WAL-004` refund;
- `WAL-005` correction approval workflow;
- pricing/Quote, promotion, payment provider or Order/provisioning behavior;
- any live PasarGuard or Marzban acceptance.

Phase `0.4.0` remains open with its carried provider live gates. This parallel Phase 0.5 foundation may not activate provider Targets or weaken provider lookup/idempotency/TLS/redaction controls.

## Verification record

Implementation CI on `259e29e6f93c2b36cd36c2f40bf669789eaab62f`:

- run `31232006308` / `#1037`;
- mandatory jobs all success;
- full suite `326 tests, 1777 assertions`;
- test artifact `test-evidence-31232006308`, ID `9014138405`;
- uploader and independently verified SHA-256 `cfda302c54c7089dbb15eb0c83a546f63bd37a142c99e5919b8408dfa85b7610`;
- artifact contained exactly five expected test/coverage/service-log files and the bounded sensitive-marker scan was clean.

## Evidence-head requirement

This traceability document and its evidence companion are accepted only after mandatory CI succeeds on their exact combined evidence head. Until then, the implementation is green but the documentation boundary is pending.
