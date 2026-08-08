# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after evidence-complete Dedicated Wallet Contention Verification.  
**Date:** 2026-08-08.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every repository write, fetch PR `#6` and use its exact `head_sha`; never continue from a copied SHA.

## Read first in a new session

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. this file;
6. active Phase 0.4 gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
7. current overlays: `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
8. Phase 0.5 evidence/traceability through `docs/53-phase-0.5-wallet-contention-traceability.md`.

## Repository invariants

- PR `#6` stays open and Draft; base `main`, head `develop/v1.0.0-completion`;
- do not merge, mark Ready, enable auto-merge, rewrite history, force-push, create temporary branches or push `main`;
- every GitHub Actions job uses the owner-controlled `freedom-staging-runner` selector only;
- every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact and independent digest inspection;
- never place credentials, tokens, API keys, passwords, subscription material or raw sensitive provider bodies in repository text, Issues, PR comments, evidence, ordinary logs or chat;
- no later phase may weaken accepted provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls;
- monetary IRR is integer; finalized balanced immutable ledger history plus active holds is wallet authority; persisted balance snapshots are derived evidence/cache only.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded harness is evidence-complete. Actual deployment execution still requires protected repository Actions Secrets configured outside repository text and manual dispatch of `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value.

The available connector cannot create/update those protected Secrets or initiate a fresh `workflow_dispatch`. Do not request or copy secret values into chat.

After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Owner decision on 2026-08-08 carries deployment-specific live acceptance to final project/release acceptance. It remains mandatory for `1.0.0`.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Six bounded financial foundations are accepted:

1. **Financial Ledger Foundation** — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. **Wallet Holds / Available Balance / Capture / Release** — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. **Wallet Reconciliation Snapshots / Expired-Hold Cleanup** — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. **Wallet Maintenance Operations** — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. **Stable Wallet Transfer (`WAL-003`)** — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. **Dedicated Wallet Contention Verification** — `docs/53-phase-0.5-wallet-contention-traceability.md` and `evidence/0.5.0/wallet-contention-verification.md`.

### Latest accepted contention boundary

Implementation verification:

- SHA `903f326040c9acd0b31645fe8fae3a75f8a9fd27`;
- CI `31240159777` / `#1128` — success;
- full suite `352 tests / 2030 assertions`;
- dedicated contention class `6 tests / 35 assertions`, zero failures/errors/skips;
- artifact `test-evidence-31240159777`, ID `9016771279`;
- independent digest `sha256:4f32e7a5c7e4cc23b985b13aa2b6f291772b75c1f9a26cedcd620b9589b973b2`.

Evidence head:

- SHA `e7a0ae17d470beb40f4933e66c7b599e0837e120`;
- CI `31241459956` / `#1131` — success;
- full suite `352 tests / 2030 assertions`;
- dedicated contention class `6 tests / 35 assertions`, zero failures/errors/skips;
- artifact `test-evidence-31241459956`, ID `9017163993`;
- independent digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`.

Accepted concurrency proof covers competing same-wallet holds without negative availability, capture/release terminal races, duplicate ledger command keys, duplicate transfer prepare/confirm and reconciliation racing a mutation. Do not weaken MariaDB locking/isolation; future financial features need their own concurrency proof.

## Next bounded increment — `WAL-004` Refund / Reversal Foundation

This is the next independent financial increment while the Phase 0.4 protected live gate remains unavailable.

Authoritative requirement: refund supports partial amounts and method-specific destination; cumulative refunds cannot exceed refundable captured value; exact card adjustment is non-refundable. Master contract additionally requires wallet refunds to return to original wallet buckets, manual external refund evidence/reference, idempotency, and permission/reason/audit for destination override.

### Initial safe boundary

Implement the provider-independent refund domain/ledger foundation first. Do **not** implement Zarinpal/NOWPayments live refund APIs or Order state ownership in this increment.

Required behavior:

1. introduce immutable refund records with a unique caller-supplied refund key and canonical payload hash;
2. reference a finalized refundable source ledger transaction and lock it before authorizing a refund;
3. support integer-IRR partial refunds and cumulative cap enforcement under concurrent requests;
4. represent destination explicitly, at minimum `wallet` and `manual_external`; provider-native destination remains declared/unavailable until the corresponding provider boundary exists;
5. wallet destination posts one compensating balanced immutable ledger transaction back to the original eligible wallet bucket/account;
6. manual external destination records required sanitized evidence/reference and does not manufacture a wallet credit;
7. exact replay returns the accepted refund; materially changed reuse conflicts;
8. destination override requires explicit actor/permission context plus reason/audit metadata; do not add an HTTP/Telegram admin surface unless an existing authorization boundary can be reused safely;
9. source refundable amount must exclude any explicitly non-refundable exact card adjustment when such source metadata exists; until Payment Intent/Quote metadata exists, do not invent a provider adjustment model;
10. direct DB writes must not mutate/delete accepted refund identity, amount, destination, source link or terminal ledger link;
11. concurrent duplicate/partial refunds must never exceed the source refundable value or create two primary effects for one refund key.

### Recommended implementation order

- inspect existing ledger transaction types/source metadata and audit/actor patterns before adding schema;
- define refund status/destination value objects or enums only where necessary;
- add the smallest MariaDB schema with FKs, unique refund key, payload hash, integer amount and terminal constraints;
- implement `WalletRefundService` (or repository-consistent equivalent) using transaction + `lockForUpdate` on source/refund rows;
- route wallet compensation through `LedgerPostingService`, never mutate prior ledger entries;
- add MariaDB feature tests for full/partial/cumulative cap, exact replay/conflict, manual-external evidence, destination override controls and DB immutability;
- add deterministic multi-process proof for concurrent partial refunds and duplicate refund keys;
- run exact implementation CI, inspect artifact/digest, then create evidence/traceability and exact evidence-head CI.

### Explicit non-claims for this boundary

Do not claim provider refund capability, Payment Intent settlement, Order refund state, referral reward cancellation, exact-card-adjustment behavior without its source metadata, or Phase `0.5.0` closure from this foundation alone.

## After `WAL-004`

Recommended order:

1. `WAL-005` balance correction/approval with compensating ledger history;
2. Payment Intent foundation + `WAL-001` top-up settlement;
3. deterministic offering pricing / immutable Quote snapshots;
4. promotions/referrals/agent pricing;
5. payment methods/providers and provider-native refund integrations.

Do not pull Order/provisioning ownership from Phase `0.6.0` forward.

## Current open items

- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers;
- all Phase `0.6.0+` owned behavior.

If the protected PasarGuard gate becomes available, follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` without exposing secrets. Otherwise continue with the bounded `WAL-004` sequence above.
