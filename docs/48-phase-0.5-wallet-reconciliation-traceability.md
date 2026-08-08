# Phase 0.5 Wallet Reconciliation Snapshots and Expired-Hold Cleanup Traceability

**Scope:** bounded remaining reconciliation-oriented portion of `WAL-002`.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation boundary:** `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`.  
**Implementation CI:** `31233135374` / `#1058` — success, 334 tests / 1886 assertions.  
**Evidence:** `evidence/0.5.0/wallet-reconciliation-snapshots.md`.

## Requirement-to-proof map

| Requirement | Foundation status | Design / implementation | Automated proof | Remaining scope |
|---|---|---|---|---|
| `WAL-002` | partial; reconciliation/snapshot/cleanup foundation accepted after evidence-head CI | `wallet_balance_snapshots`; `WalletReconciliationService`; `ExpiredWalletHoldCleanupService`; prior accepted ledger/hold services | `WalletReconciliationTest` initial/matched/refreshed snapshots, stale snapshot non-authority, fail-closed inconsistency, immutable snapshot DB guards, bounded cleanup/review rows | dedicated multi-process contention/stress; operational command/schedule/alerting |
| `DAT-002` | satisfied for this boundary | integer snapshot amounts and prior `IrrMoney` ledger/hold values | MariaDB feature tests | broader project fiat/crypto representation remains separate |
| `DAT-003` | satisfied for this schema | FKs, self-reference to prior snapshot, arithmetic/state checks, indexes, transaction/lock boundaries | migration-backed MariaDB tests | broader project schema remains separate |
| `DAT-004` | satisfied for snapshot/reconciliation history | append-only snapshot insertions, update/delete rejection, no financial-history repair-by-mutation | direct DB bypass tests | other financial/audit families remain separate |
| `QUA-001` | satisfied for this bounded implementation after evidence-head CI | production code, tests, exact implementation CI, retained artifact, independent digest | mandatory CI #1058 | full Phase 0.5 closure remains open |

## Authoritative balance and snapshot separation

The accepted wallet source of truth remains:

1. finalized immutable ledger entries for the wallet account;
2. active immutable-lifecycle wallet holds;
3. fresh transaction/row-lock validation at the point of authoritative calculation.

`wallet_balance_snapshots` is intentionally a derived append-only read model. No hold placement, capture or other financial mutation reads a snapshot to authorize an effect. A structurally valid but stale snapshot therefore cannot change authoritative available balance.

## Reconciliation state proof

Each reconciliation pass:

- validates/locks the wallet account through the accepted authoritative balance path;
- derives ledger balance, active holds and available balance;
- captures the latest finalized ledger-entry coordinate and latest active-hold coordinate;
- hashes those values into a deterministic source fingerprint;
- compares only with the latest prior snapshot for the same wallet account;
- inserts `initial` when no prior snapshot exists;
- inserts `matched` when the authoritative fingerprint is unchanged;
- inserts `refreshed` when prior evidence is stale or authoritative state changed;
- never updates/deletes the compared snapshot.

MariaDB checks independently reject negative snapshot values, active holds above ledger balance, inconsistent available arithmetic, malformed comparison state and invalid compared-snapshot requirements.

## Fail-closed inconsistency proof

The authoritative balance path already rejects negative ledger-derived balance and active holds greater than ledger balance. Reconciliation therefore writes no snapshot for an inconsistent financial state. This preserves a review/reconciliation-required signal instead of caching an impossible negative available balance.

## Expired-hold cleanup proof

Cleanup is intentionally bounded and service-level:

- caller limit must be `1..500`;
- candidate query is only `active` plus `expires_at <= now`, ordered by hold ID;
- each candidate transitions through the already accepted `WalletHoldService::release()` path with one deterministic system reason;
- future, captured and released rows are excluded;
- each row is isolated at the release-operation level so a failed row is returned in `reviewHoldIds` and does not silently disappear;
- reruns are safe because successful releases are terminal and are absent from the next active-expired query;
- cleanup writes no ledger transaction and therefore cannot fabricate a financial debit/credit effect.

## Operational scheduling status

A production maintenance command/schedule is deliberately not claimed by this boundary. The core reconciliation and cleanup services are deterministic and test-backed first. A later bounded operational increment may register a command and schedule using the repository's single-scheduler conventions (`withoutOverlapping` / `onOneServer` where applicable), add safe metrics/alerts and verify failure visibility.

## Concurrency status

The accepted ledger/hold/reconciliation paths use MariaDB transactions, account/hold locks, unique logical keys and append-only records. This increment does not claim dedicated multi-process contention/stress evidence for simultaneous hold placement/capture/release/reconciliation. That remains an explicit `WAL-002` verification item rather than being inferred from unit/feature behavior.

## Explicit non-claims

This traceability does not claim completion of:

- operational scheduling/alerting for reconciliation or expired-hold cleanup;
- dedicated multi-process wallet contention stress;
- `WAL-001` Payment Intent top-up;
- `WAL-003` wallet transfer;
- `WAL-004` refund;
- `WAL-005` balance correction;
- pricing/Quote, promotions or payment-provider behavior;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance.

Phase `0.4.0` remains open. No financial foundation may activate real provider Targets or weaken provider lookup/idempotency/TLS/redaction controls.

## Verification record

Implementation CI on `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`:

- run `31233135374` / `#1058`;
- mandatory jobs all success;
- full suite `334 tests, 1886 assertions`;
- test artifact `test-evidence-31233135374`, ID `9014519274`;
- uploader and independently verified SHA-256 `fcad956d65cbcac41c4c103bf09e7d5d00e43e40ed6fcb398e02bbaada78f80f`;
- artifact contained exactly five expected test/coverage/service-log files and the bounded sensitive-marker scan was clean.

## Evidence-head requirement

This traceability document and its evidence companion are accepted only after mandatory CI succeeds on their exact combined evidence head. Until then, the implementation is green but the documentation boundary remains pending.
