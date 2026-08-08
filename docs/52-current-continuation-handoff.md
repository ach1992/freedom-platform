# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after evidence-complete `WAL-005` Wallet Correction / Approval Foundation.  
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
8. latest Phase 0.5 evidence/traceability: `evidence/0.5.0/wallet-correction-approval-foundation.md` and `docs/55-phase-0.5-wallet-correction-traceability.md`.

## Repository invariants

- PR `#6` stays open and Draft; base `main`, head `develop/v1.0.0-completion`;
- do not merge, mark Ready, enable auto-merge, rewrite history, force-push, create temporary branches or push `main`;
- every GitHub Actions job uses the owner-controlled `freedom-staging-runner` selector only;
- every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact and independent digest inspection;
- never place credentials, tokens, API keys, passwords, subscription material or raw sensitive provider bodies in repository text, Issues, PR comments, evidence, ordinary logs or chat;
- no later phase may weaken accepted provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls;
- monetary IRR is integer; finalized balanced immutable ledger history plus active holds is wallet authority; persisted balance snapshots are derived evidence/cache only;
- refund/correction never edits prior ledger history; accepted changes are compensating transactions with immutable business records;
- browser returns, redirects or customer-submitted claims never prove payment capture;
- no paid provisioning occurs before authoritative capture, and Orders/provisioning remain Phase `0.6.0` ownership.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded harness is evidence-complete. Actual deployment execution still requires protected repository Actions Secrets configured outside repository text and manual dispatch of `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value.

The available connector cannot create/update those protected Secrets or initiate a fresh `workflow_dispatch`. Do not request or copy secret values into chat.

After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Deployment-specific live acceptance is owner-scheduled for final project/release acceptance and remains mandatory for `1.0.0`.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Eight bounded financial foundations are accepted:

1. **Financial Ledger Foundation** — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. **Wallet Holds / Available Balance / Capture / Release** — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. **Wallet Reconciliation Snapshots / Expired-Hold Cleanup** — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. **Wallet Maintenance Operations** — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. **Stable Wallet Transfer (`WAL-003`)** — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. **Dedicated Wallet Contention Verification** — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. **Wallet Refund / Reversal Foundation (`WAL-004`)** — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. **Wallet Correction / Approval Foundation (`WAL-005`)** — `docs/55-phase-0.5-wallet-correction-traceability.md` and `evidence/0.5.0/wallet-correction-approval-foundation.md`.

### Latest accepted boundary — `WAL-005`

Implementation:

- SHA `fd3d579d9f38004310d7ea638e351813d2f46ef5`;
- CI `31260299072` / `#1186` — success;
- full suite `371 tests / 2197 assertions`;
- dedicated correction verification `11 tests / 98 assertions`;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- independent digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Evidence:

- SHA `ef5504081a42687eb712e9cd47306cd9dcc9a864`;
- CI `31260549403` / `#1188` — success;
- full suite `371 / 2197`;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- independent digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`.

Accepted behavior includes immutable preview/confirmation, fresh ledger/hold authority, negative-availability prevention, execution-time authorization and policy revalidation, distinct sensitive approval for policy-selected large non-owner corrections, exact approval replay binding, compensating ledger entries, DB immutability and real-MariaDB concurrency proof.

## Next bounded increment — Payment Intent + `WAL-001` External Cash-Wallet Top-up Settlement

Authoritative requirements:

- `PAY-001`: gateway/payment-method eligibility uses current authoritative account/tier/tag/history, amount/action/offering, identity, time/limits, explicit overrides and health precedence;
- `PAY-002`: Payment Intents may be controlled/duplicated as intents, but only one captured settlement may complete the financial effect; browser returns never prove payment;
- `PAY-003`: duplicate external/internal events return prior result without second capture/provisioning;
- `WAL-001`: external wallet top-up uses a Payment Intent and posts exactly one balanced **cash** wallet ledger transaction only after capture.

### Initial provider-independent scope

Implement the smallest top-up-only Payment Intent application/domain boundary. Reuse the existing `App\Modules\Payments\Application\Contracts\PaymentProvider` and normalized provider DTOs; do not duplicate provider contracts and do not introduce Order/provisioning ownership.

Required behavior:

1. explicit Payment Intent state using the existing `PaymentIntentState` vocabulary; top-up path begins `created`, may wait/verify, and only authoritative capture reaches `captured`;
2. immutable caller creation/idempotency key and canonical payload hash; exact replay returns prior intent, materially changed reuse conflicts;
3. intent purpose/action is wallet top-up, bound to one user, one active owned **cash** wallet account and one positive integer-IRR amount; promotional bucket is not an external top-up target;
4. persist normalized provider/method identity and safe intent metadata without credentials or raw secret payloads;
5. model Payment Attempt and Provider Transaction/Event identity sufficiently to enforce unique normalized provider evidence and duplicate-event replay;
6. a browser return/redirect/customer claim may advance no financial authority by itself;
7. capture accepts only normalized authoritative provider evidence that matches intent/provider/amount/currency and a captured/success terminal transaction state;
8. capture transaction locks the intent and wallet authority rows, inserts/loads unique provider consumption/settlement identity and posts exactly one balanced ledger transaction crediting the cash wallet after capture;
9. exact duplicate capture/event returns the accepted intent/settlement/ledger IDs; conflicting provider evidence fails closed;
10. no Order row, provisioning operation, service activation or Phase `0.6.0` consequence is created by this top-up-only boundary;
11. append-only state/evidence/audit history is safe and bounded; no raw provider secrets/bodies;
12. MariaDB independent-process tests cover simultaneous duplicate capture/event and prove one top-up ledger effect;
13. exact implementation-head CI/artifact/digest, then evidence/traceability and exact evidence-head CI/artifact/digest are mandatory before acceptance.

### Recommended implementation order

- inspect existing payment state/DTO/provider contracts and ledger posting conventions;
- define minimal migrations for payment methods/intents/attempts/provider transactions/events/top-up settlement with FKs, unique keys, state/check constraints and immutability guards;
- implement intent creation/replay first;
- implement safe provider evidence normalization boundary without live credentials;
- implement authoritative capture/top-up settlement through `LedgerPostingService` under transaction/locks;
- add feature/idempotency/tamper tests and independent-process duplicate-capture contention proof;
- verify exact implementation head;
- create evidence + traceability, verify exact evidence head;
- reconcile status/overlays/Issue `#8`, then continue to pricing/Quote.

### Explicit non-claims

Do not claim live provider compatibility, gateway health acceptance, provider-native refund, Order paid state, provisioning, service activation, pricing/Quote, promotions/referrals, or customer Telegram/HTTP UX from this first Payment Intent boundary.

## After `WAL-001`

Recommended independent order:

1. deterministic offering pricing / immutable Quote snapshots;
2. promotions/referrals/agent pricing;
3. payment-method eligibility refinements and provider implementations;
4. provider-native refund integrations.

Do not pull Order/provisioning ownership from Phase `0.6.0` forward.

## Current open items

- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- `WAL-001`, pricing/Quote/promotions/payment providers;
- provider-native refund/payment behavior beyond accepted wallet foundations;
- all Phase `0.6.0+` owned behavior.

If the protected PasarGuard gate becomes available, follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` without exposing secrets. Otherwise continue autonomously with the Payment Intent + `WAL-001` sequence above.
