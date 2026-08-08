# Current Continuation Handoff

**Status:** authoritative human continuation checkpoint after accepted `WAL-003`.  
**Date:** 2026-08-08.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every repository write, fetch PR `#6` and use its exact `head_sha`; never continue from a copied SHA.

## Read first in a new session

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. this file;
6. for the active Phase 0.4 human gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
7. for parallel Phase 0.5 financial work: `docs/45-phase-0.5-financial-ledger-traceability.md` through `docs/51-phase-0.5-wallet-transfer-traceability.md`.

## Repository invariants

- PR `#6` stays open and Draft;
- base remains `main`; head remains `develop/v1.0.0-completion`;
- do not merge, mark Ready, enable auto-merge, rewrite history, force-push, create temporary branches or push `main`;
- exact implementation CI and exact evidence-head CI are required for every accepted bounded increment;
- real provider Targets remain disabled/unverified until applicable controlled live acceptance passes;
- never put provider credentials, API keys, tokens, passwords, subscription material or raw sensitive provider bodies in repository text, Issues, PR comments, evidence or ordinary logs.

## Active phase and human-controlled blocker

Phase `0.4.0` / Issue `#7` is still the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded live-acceptance harness is evidence-complete, but the actual controlled deployment run still needs two protected repository Actions Secrets configured outside repository text:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

Then manually dispatch `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value. The currently available GitHub connector can inspect/retry Actions runs but cannot create/update repository Actions Secrets or initiate a fresh `workflow_dispatch`.

Do not paste secret values into chat or repository artifacts.

### Marzban `v0.8.4`

Owner decision on 2026-08-08: Marzban live acceptance is deferred to final project/release acceptance. This is scheduling only; the mandatory `1.0.0` requirement remains open.

### Provider safety state

Preserve all of these:

- authoritative remote lookup before create;
- lookup unavailable => no create;
- automatic adoption requires provider-specific accepted create-equivalence proof;
- missing proof => Manual Review/no create;
- mismatch => conflict/no overwrite;
- uncertain mutation => authoritative discovery before retry;
- conflicting idempotency-key reuse never overwrites the original primary effect;
- TLS verification is never disabled;
- real provider mutation/delivery capabilities and Targets remain fail-closed until explicit live acceptance.

## Accepted parallel Phase 0.5 financial boundaries

Phase `0.5.0` / Issue `#8` is not closed, but the following bounded foundations are evidence-complete and may be reused.

### 1. Financial Ledger Foundation

- implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f`, CI `#1037`, 326 tests / 1777 assertions;
- evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c`, CI `#1039`;
- evidence `evidence/0.5.0/financial-ledger-foundation.md`;
- traceability `docs/45-phase-0.5-financial-ledger-traceability.md`.

Accepted: integer IRR, balanced append-only postings, wallet/system account constraints, exact replay/conflict, transaction/row-lock foundation and DB immutability.

### 2. Wallet Holds / Available Balance / Capture / Release

- implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`, CI `#1046`, 331 tests / 1834 assertions;
- evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`, CI `#1048`;
- evidence `evidence/0.5.0/wallet-holds-capture-release.md`;
- traceability `docs/46-phase-0.5-wallet-holds-traceability.md`.

Accepted: active holds reduce authoritative ledger-derived available balance, placement replay/conflict, one capture ledger effect, release without ledger rewrite, terminal/non-deletable hold history.

### 3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup

- implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `#1058`, 334 tests / 1886 assertions;
- evidence `57719972291b0553e76da6f8db50a8190a807044`, CI `#1060`;
- evidence `evidence/0.5.0/wallet-reconciliation-snapshots.md`;
- traceability `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.

Accepted: append-only non-authoritative snapshots, authoritative ledger+active-hold reconciliation, fail-closed inconsistent state, bounded explicit cleanup/review. Transfer holds are excluded from generic cleanup.

### 4. Wallet Maintenance Operations

- implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6`, CI `#1066`, 338 tests / 1905 assertions;
- evidence `fa0cc2056459f171c32e0260422a465891766629`, CI `#1068`;
- evidence `evidence/0.5.0/wallet-maintenance-operations.md`;
- traceability `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

Accepted: bounded `wallet:maintenance`, aggregate-safe JSON output, every-five-minutes Laravel Scheduler registration, `withoutOverlapping()` and `onOneServer()`, snapshots remain non-authoritative.

### 5. Stable Wallet Transfer (`WAL-003`)

Implementation:

- SHA `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`;
- CI `31235232052` / `#1084` — success;
- 346 tests / 1995 assertions;
- artifact `test-evidence-31235232052`, ID `9015155851`;
- independent digest `sha256:c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

Evidence:

- SHA `68f06fbd4ae9bb1bdba004968e57c84a13871e15`;
- CI `31235552266` / `#1092` — success;
- 346 tests / 1995 assertions;
- artifact `test-evidence-31235552266`, ID `9015258508`;
- independent digest `sha256:f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`;
- evidence `evidence/0.5.0/wallet-transfer.md`;
- traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`.

Accepted transfer behavior includes default-disabled policy, stable recipient ID resolution, execution-time active-customer/account revalidation, integer amount/fee/limit snapshots, prepare reservation without transfer ledger effect, atomic sender/recipient/fee posting on confirmation, exact replay/conflict, terminal cancellation/expiry, generic-cleanup exclusion for transfer holds and database terminal-state/immutability barriers.

## Next recommended bounded increment

### Dedicated Wallet Contention Verification (`WAL-002` remaining proof)

This is the cleanest next task before new financial behavior.

Goal: provide real concurrent-process evidence that accepted ledger/hold/available-balance/reconciliation/transfer invariants survive contention rather than inferring that from row-lock code and single-process feature tests.

Required scenarios should be deliberately bounded and use the real MariaDB test service:

1. two concurrent hold placements against the same wallet where combined requested value exceeds available balance — at most one admissible reservation set may succeed and authoritative available balance never goes negative;
2. concurrent capture/release attempts on one hold — exactly one terminal transition/effect, later conflicting attempt fails or exact-replays safely;
3. duplicate concurrent ledger command key — one finalized transaction/effect only;
4. duplicate concurrent transfer preparation/confirmation — one transfer hold and one completed ledger effect only;
5. reconciliation concurrent with financial mutation — snapshot may reflect a before/after authoritative state but must remain internally consistent and must never authorize the mutation;
6. verify no deadlock/retry path manufactures a duplicate primary effect; any unresolved deadlock/timeout is surfaced rather than silently accepted.

Prefer a deterministic subprocess/barrier test harness over timing sleeps. Do not weaken transaction isolation/locking just to make the test pass. Record deadlock handling explicitly if MariaDB returns one.

Completion requires its own exact implementation CI, artifact/digest, evidence/traceability and exact evidence-head CI. Do not call all of `WAL-002` complete until this proof is accepted.

## After contention verification

Recommended order:

1. `WAL-004` refund/reversal using compensating immutable ledger entries and exact replay/conflict;
2. `WAL-005` balance correction with explicit reason/authorization/approval boundary and compensating ledger history;
3. Payment Intent foundation and `WAL-001` external top-up settlement;
4. deterministic offering pricing / immutable Quote snapshots;
5. promotions/referrals/agent pricing;
6. payment methods/providers from Issue `#8`.

Do each as a separate bounded evidence lifecycle. Do not pull Order/provisioning state from Phase `0.6.0` forward.

## Known open items at this checkpoint

- PasarGuard controlled live execution: human-protected Secrets + manual dispatch;
- PasarGuard coordinator/fault/Target-activation live rows after the guarded run;
- Marzban live acceptance at final project/release acceptance;
- Phase `0.4.0` closure audit;
- dedicated multi-process wallet contention verification;
- no automated scheduled sweep for untouched expired pending wallet transfers is currently claimed;
- `WAL-001`, `WAL-004`, `WAL-005`;
- pricing/Quote/promotions/payment providers and the rest of Phase `0.5.0`;
- all later phase-specific work.

## Stop/continue rule

If protected PasarGuard Secrets become available, do not expose them: follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and execute the live gate in strict matrix order. Otherwise continue only with independent later-phase work that does not weaken or falsely close Phase `0.4.0`.

This handoff is deliberately written so a new session can begin from current authoritative docs without reconstructing prior chat history.
