# Current Continuation Handoff

**Status:** authoritative continuation checkpoint with implementation-verified `PAY-002` / `PAY-003` / `WAL-001` Payment Intent / External Cash-Wallet Top-up candidate in exact evidence-head gating.  
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
8. current Phase 0.5 candidate: `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md` and `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`;
9. latest accepted Phase 0.5 boundary: `evidence/0.5.0/wallet-correction-approval-foundation.md` and `docs/55-phase-0.5-wallet-correction-traceability.md`.

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

Latest accepted boundary remains `WAL-005` at implementation `fd3d579d9f38004310d7ea638e351813d2f46ef5` / CI `#1186` and evidence `ef5504081a42687eb712e9cd47306cd9dcc9a864` / CI `#1188`.

## Current candidate — Payment Intent + `WAL-001` External Cash-Wallet Top-up

The provider-independent top-up implementation is exact-head verified and now requires only its exact evidence-head lifecycle before it can join the accepted parallel chain.

### Implementation verification

- implementation SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — all mandatory jobs success;
- full suite **384 tests / 2292 assertions**;
- dedicated top-up suites **13 tests / 95 assertions**, zero failures/errors/skips;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- uploader and independently recalculated SHA-256 `3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`;
- evidence candidate `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability candidate `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

Independent artifact inspection found exactly JUnit, full test log, Clover coverage and two dependency-service evidence files. The artifact contains no known CI credential value, bearer/basic authorization value, private-key header or retained raw provider secret material.

### Implementation-verified behavior

1. top-up creation uses an immutable caller key and canonical payload binding user, cash-wallet ID, provider code, positive integer-IRR amount and currency;
2. exact creation-key/payload reuse returns the same intent; changed reuse conflicts;
3. the target must be one active owned IRR **cash** wallet; promotional bucket is rejected;
4. the existing `PaymentIntentState` vocabulary is reused and append-only state history is recorded;
5. browser-return/customer claim/non-authoritative evidence cannot capture or credit a wallet;
6. first capture requires normalized `Success + Authoritative + Settled` evidence with exact provider/amount/currency match and settlement timestamp;
7. provider events and transactions are unique, append-only and exact-replay validated; database triggers independently require authoritative settled event linkage;
8. one accepted settlement posts exactly one finalized balanced `wallet_external_top_up` transaction: debit `system.wallet.external_top_up.clearing`, credit the immutable cash wallet;
9. accepted settlement exact replay returns the same settlement/ledger IDs without a second effect, including after later wallet deactivation;
10. direct database insertion cannot manufacture provider authority or settlement linkage;
11. safe provider evidence forbids credential/authorization/cookie/signature/private/raw-payload fields and is DB-bounded to a JSON object of at most 32 fields / 8192 bytes;
12. independent-process MariaDB duplicate capture resolves to one primary effect plus replay; concurrent cross-intent provider-event/transaction reuse accepts only one top-up effect;
13. bounded worker/InnoDB timeouts surface unresolved contention as failures instead of hanging CI.

### Exact evidence-head gate

Do not mark this increment accepted until the final documentation/evidence head passes:

- Repository preflight / project-control verification;
- Secret scan;
- PHP static quality and repository policy;
- dependency/license policy;
- full MariaDB/authenticated Redis suite;
- retained `test-evidence-<run>` artifact with actual test/assertion counts;
- independent artifact SHA-256 recomputation and safe-content inspection.

After the gate passes, update evidence/status from candidate to accepted, comment Issue `#8`, and immediately continue to `BUY-002` Pricing / Quote.

### Explicit non-claims

The candidate does not claim:

- `PAY-001` payment-method eligibility or gateway rule engine;
- real gateway/provider implementation, health or live compatibility;
- provider-native refund/reversal;
- pricing/Quote, promotions/referrals or agent pricing;
- Order/provisioning/service lifecycle;
- customer/admin payment UX;
- Phase `0.4.0`, Phase `0.5.0` or release closure.

## Next bounded increment after WAL-001 acceptance — `BUY-002` deterministic Pricing / Quote

Build a provider-independent immutable Quote snapshot before promotions/providers. Reuse accepted Offering/custom-plan arithmetic where appropriate rather than duplicating Phase 0.4 calculation semantics.

Required first-boundary behavior:

1. all monetary values are bounded integer IRR; no monetary float or implicit rounding;
2. immutable quote/idempotency key plus canonical request/config payload hash; exact replay returns prior Quote, changed reuse conflicts;
3. snapshot base offering price and relevant Offering/config identity/version;
4. represent account/agent price override input explicitly and deterministically; do not infer hidden mutable state later;
5. represent discount input/result explicitly, including zero discount; final price is deterministic and non-negative;
6. persist `base_price`, override, discount, final price, `IRR`, validity start/end and immutable configuration snapshot/hash;
7. accepted Quote is update/delete guarded and remains interpretable after later Offering/pricing changes;
8. expiration is explicit; an expired Quote cannot silently become current pricing authority;
9. Quote generation has no wallet debit/capture, Payment Intent capture, Order paid state, provisioning or service activation effect;
10. first pricing boundary does not implement promotions/referrals/provider routing; those remain later increments;
11. feature tests cover base/override/discount precedence, boundary/overflow/negative rejection, validity, exact replay/conflict and DB immutability;
12. exact implementation-head and evidence-head CI/artifact/digest lifecycle is mandatory.

Before implementation, inspect the authoritative `BUY-002` wording, `PlanOfferingDefinition`, existing custom-plan calculation snapshot/arithmetic and any accepted agent-pricing structures. Do not invent a second incompatible pricing formula.

## After `BUY-002`

Recommended independent order:

1. promotions/referrals/agent pricing;
2. payment-method eligibility refinements (`PAY-001`) and provider implementations;
3. provider-native refund integrations;
4. Phase `0.5.0` reconciliation/closure only when all owned requirements have evidence.

Do not pull Order/provisioning ownership from Phase `0.6.0` forward.

## Current open items

- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- current `WAL-001` exact evidence-head gate;
- `BUY-002` pricing/Quote, promotions/referrals/agent pricing, `PAY-001` and payment providers;
- provider-native refund/payment behavior beyond accepted provider-independent wallet foundations;
- all Phase `0.6.0+` owned behavior.

If the protected PasarGuard gate becomes available, follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` without exposing secrets. Otherwise complete the current `WAL-001` evidence gate and continue autonomously with the `BUY-002` sequence above.
