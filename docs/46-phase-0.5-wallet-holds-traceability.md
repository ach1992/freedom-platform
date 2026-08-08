# Phase 0.5 Wallet Holds, Available Balance, Capture and Release Traceability

**Scope:** bounded `WAL-002` hold lifecycle foundation only.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation boundary:** `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`.  
**Implementation CI:** `31232610814` / `#1046` — success, 331 tests / 1834 assertions.  
**Evidence:** `evidence/0.5.0/wallet-holds-capture-release.md`.

## Requirement-to-proof map

| Requirement | Foundation status | Design / implementation | Automated proof | Remaining scope |
|---|---|---|---|---|
| `WAL-002` | partial; holds/capture/release foundation accepted after evidence-head CI | `wallet_holds`; `WalletHoldService`; ledger-derived balance; active-hold reservation; capture through `LedgerPostingService`; immutable DB guards | `WalletHoldLifecycleTest` placement/replay/conflict, available-balance rejection, capture once/replay conflict, release once/replay conflict, expiry fail-closed, DB immutability | persisted snapshots, automated reconciliation, expired-hold sweep, dedicated multi-process stress |
| `DAT-002` | satisfied for this boundary | `IrrMoney`, integer `amount_irr`, integer ledger amounts | wallet hold feature suite and prior `IrrMoneyTest` | project-wide fiat/crypto representation remains broader scope |
| `DAT-003` | satisfied for this schema | FKs, unique hold key, checks, indexes, state constraints, transaction and row-lock boundaries | MariaDB-backed feature tests | broader project schema remains separate |
| `DAT-004` | satisfied for hold/ledger history | hold update/delete triggers plus accepted append-only ledger guards | direct DB bypass tests | other financial/audit record families remain separate |
| `QUA-001` | satisfied for this bounded implementation after evidence-head CI | requirement annotations, production code, exact-head CI, retained artifact, independent digest | mandatory CI #1046 | full Phase 0.5 closure traceability remains open |

## State machine proof

Accepted hold transitions are intentionally narrow:

- `active -> captured` exactly once;
- `active -> released` exactly once;
- `captured` and `released` are terminal and database-enforced immutable;
- capture after expiry is rejected until authoritative cleanup releases the hold;
- terminal rows are never deleted.

The database state check also requires capture/release companion fields to match the state, preventing malformed terminal records through ordinary writes.

## Available-balance proof

For the selected active IRR wallet liability account:

- ledger balance is derived from finalized immutable ledger entries;
- active holds are summed independently from ledger balance;
- available balance is `ledger balance - active holds`;
- if ledger debits exceed credits, or active holds exceed ledger balance, the read fails as reconciliation-required rather than returning a negative balance;
- a new hold locks the wallet account and is rejected if its amount exceeds the resulting available balance.

No mutable cached balance column is introduced by this increment.

## Replay and duplicate-effect proof

### Hold placement

`wallet_holds.hold_key` is unique and a canonical payload hash covers owner, wallet account, amount, source identity and expiry. Exact replay returns the accepted hold. Changed payload conflicts. Unique-race recovery is allowed to return only an exact hash match.

### Capture

Capture uses a deterministic ledger command key derived from the hold key and records the hold as the source of the balanced ledger transaction. On capture replay, the service verifies the recorded transaction is finalized with the expected transaction type, source identity, amount, entry count, wallet debit and requested system-offset credit. A different offset account therefore cannot silently reuse the original effect.

### Release

Release has no new ledger effect. Same hold plus same accepted reason replays; a different reason conflicts. Captured holds cannot be released and released holds cannot be captured.

## Atomicity and lock ordering

The hold operation executes within MariaDB transactions. Placement serializes against the wallet account before computing available balance and rechecks the unique hold key. Capture locks the hold and delegates the financial effect to the accepted ledger posting service within the same database connection/transaction scope before transitioning the hold. Release locks the hold and wallet account before terminal transition.

This establishes isolation-aware production behavior but does not claim a dedicated multi-process contention stress result. That executable stress remains a later `WAL-002` verification increment.

## Expiry semantics

Expiry never silently frees reserved funds. An expired row still has `active` status and remains part of active holds until an explicit release/reconciliation action transitions it. Capture of an expired active hold fails closed. A scheduled expiry sweep and durable reconciliation report remain open work.

## Explicit non-claims

This traceability does not claim completion of:

- persisted wallet balance snapshots or ledger-vs-cache reconciliation;
- automated expired-hold release;
- dedicated multi-process hold/capture contention stress;
- `WAL-001` Payment Intent top-up;
- `WAL-003` wallet transfer;
- `WAL-004` refund;
- `WAL-005` balance correction;
- pricing/Quote, promotion, payment-provider or Order/provisioning behavior;
- PasarGuard or Marzban live acceptance.

Phase `0.4.0` remains open. This parallel financial work cannot activate provider Targets or weaken provider lookup/idempotency/TLS/redaction controls.

## Verification record

Implementation CI on `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`:

- run `31232610814` / `#1046`;
- mandatory jobs all success;
- full suite `331 tests, 1834 assertions`;
- test artifact `test-evidence-31232610814`, ID `9014323613`;
- uploader and independently verified SHA-256 `9a5154dd6cb0baeb52880c8fbc86e06fcdccd98b9bd62cc41eed42ca745f06f1`;
- artifact contained exactly five expected test/coverage/service-log files and the bounded sensitive-marker scan was clean.

## Evidence-head requirement

This traceability document and its evidence companion are accepted only after mandatory CI succeeds on their exact combined evidence head. Until then, implementation is green but the documentation boundary remains pending.
