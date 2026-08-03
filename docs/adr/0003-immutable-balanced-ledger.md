# ADR 0003: Immutable Balanced Ledger

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

Wallet cash, promotional credit, transfers, holds, purchases, refunds, fees, rewards, and corrections must remain reconcilable under concurrency. A mutable balance cannot explain history or safely support compensation.

## Decision

Use append-only balanced ledger transactions with integer IRR entries. Cash and promotional credit are separate accounts/buckets. Posted entries are never edited or deleted; corrections, reversals, and refunds are new compensating transactions. Wallet holds are separate immutable-lifecycle records that reduce available balance and can be captured or released once. Balance snapshots are caches/checkpoints, never source of truth.

The posting service locks affected accounts in deterministic order, verifies debit equals credit, prevents negative available balance, inserts entries, finalizes the transaction, and writes outbox events in one MariaDB transaction. Unique operation keys prevent repeated effects. Reconciliation recomputes transaction and account balances independently.

## Consequences

- Complete audit and deterministic balance reconstruction are possible.
- Cross-row balance is enforced by the posting service and proven by reconciliation/tests; a simple database check constraint cannot sum child rows.
- Reads may use snapshots only when continuously reconcilable.
- Operational tools must never expose arbitrary row editing; administrators post reasoned compensation.
- A mismatch is Critical and freezes affected automation.

## Rejected alternatives

- Mutable `wallet.balance` source of truth: race-prone and unauditable.
- Single-sided transaction history: cannot prove global balance or detect missing counter-entry.
- Financial event sourcing for the whole product: unnecessary complexity; the immutable ledger supplies the required accounting record.
