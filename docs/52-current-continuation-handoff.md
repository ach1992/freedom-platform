# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after accepted `PAY-002` / `PAY-003` / `WAL-001` Payment Intent / External Cash-Wallet Top-up; next independent increment is `BUY-002` deterministic Pricing / immutable Quote snapshot.  
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
8. latest accepted Phase 0.5 evidence: `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md` and `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

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
- no paid provisioning occurs before authoritative capture, and Order/provisioning state transitions remain Phase `0.6.0` ownership.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded harness is evidence-complete. Actual deployment execution still requires protected repository Actions Secrets configured outside repository text and manual dispatch of `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value.

The available connector cannot create/update those protected Secrets or initiate a fresh `workflow_dispatch`. Do not request or copy secret values into chat.

After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Deployment-specific live acceptance is owner-scheduled for final project/release acceptance and remains mandatory for `1.0.0`.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Nine bounded financial foundations are accepted:

1. **Financial Ledger Foundation** — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. **Wallet Holds / Available Balance / Capture / Release** — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. **Wallet Reconciliation Snapshots / Expired-Hold Cleanup** — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. **Wallet Maintenance Operations** — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. **Stable Wallet Transfer (`WAL-003`)** — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. **Dedicated Wallet Contention Verification** — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. **Wallet Refund / Reversal Foundation (`WAL-004`)** — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. **Wallet Correction / Approval Foundation (`WAL-005`)** — `docs/55-phase-0.5-wallet-correction-traceability.md`;
9. **Payment Intent / External Cash-Wallet Top-up (`PAY-002`, `PAY-003`, `WAL-001`)** — `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

## Latest accepted boundary — `PAY-002` / `PAY-003` / `WAL-001`

Implementation:

- SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — all mandatory jobs success;
- full suite **384 tests / 2292 assertions**;
- dedicated top-up suites **13 tests / 95 assertions**;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- independent SHA-256 `3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`.

Evidence:

- SHA `76ea847d4d1f626893b925bbfe5263e1dc1398e4`;
- CI `31265901691` / `#1220` — all mandatory jobs success;
- full suite **384 / 2292**;
- artifact `test-evidence-31265901691`, ID `9024152227`;
- independent SHA-256 `dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`.

Independent evidence-head inspection found exactly JUnit, full test log, Clover coverage and two dependency-service evidence files. JUnit reports 384 tests / 2292 assertions with zero failures/errors/skips. The dedicated top-up suites remain 13 / 95 with zero failures/errors/skips. Safe scanning found no known CI credential value, bearer/basic authorization value, private-key header or raw provider secret material.

Accepted behavior:

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

No real gateway/provider, `PAY-001`, provider-native refund, pricing/Quote, promotions/referrals/agent pricing, Order/provisioning, payment UX or phase/release closure is claimed by this boundary.

## Current bounded increment — `BUY-002` deterministic Pricing / immutable Quote

Build a provider-independent immutable Quote snapshot before promotions/providers. The authoritative requirement is that Quotes contain immutable **base, override, discount, final amount, currency, validity, and configuration snapshots**. Quote state belongs to the Orders aggregate, but this first boundary must not create or transition a paid Order.

Reuse accepted Offering/custom-plan semantics rather than inventing a second incompatible pricing formula. Existing Offering definitions expose integer-IRR base price and immutable commercial configuration. Existing custom-plan calculation already demonstrates version/configuration-hash snapshots and overflow-safe integer arithmetic. The first Quote boundary should compose these proven patterns.

Required behavior:

1. all monetary values are bounded integer IRR; no monetary float or implicit rounding;
2. immutable quote/idempotency key plus canonical request/config payload hash; exact replay returns prior Quote, changed reuse conflicts;
3. snapshot current Offering identity/configuration and base price;
4. account/agent override input is explicit, deterministic and snapshotted; no hidden later lookup may reinterpret an accepted Quote;
5. discount input/result is explicit, including zero discount; final amount is deterministic and non-negative;
6. persist base, override, discount, final amount, `IRR`, validity start/end and immutable configuration snapshot/hash;
7. accepted Quote is update/delete guarded and remains interpretable after later Offering/pricing changes;
8. expiration is explicit; an expired Quote cannot silently become current pricing authority;
9. Quote creation has no wallet debit/capture, Payment Intent capture, paid Order transition, provisioning or service activation effect;
10. first pricing boundary does not implement promotions/referrals/provider routing; those remain later increments;
11. feature tests cover base/override/discount precedence, boundary/overflow/negative rejection, validity, exact replay/conflict, later Offering mutation stability and DB immutability;
12. exact implementation-head and evidence-head CI/artifact/digest lifecycle is mandatory.

Before schema/service write, inspect current `plan_offerings` columns/configuration hash/version behavior, custom-plan snapshot/guards and accepted agent-pricing structures. Prefer a dedicated `quotes` table under the Orders module with explicit immutable columns and a bounded JSON configuration snapshot only where relational queryability is not required.

## After `BUY-002`

Recommended independent order:

1. promotions/referrals/agent pricing;
2. payment-method eligibility refinements (`PAY-001`) and provider implementations;
3. provider-native refund integrations;
4. Phase `0.5.0` reconciliation/closure only when all owned requirements have evidence.

Do not pull paid Order/provisioning/service ownership from Phase `0.6.0` forward.

## Current open items

- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- current `BUY-002` pricing/Quote increment;
- promotions/referrals/agent pricing, `PAY-001` and payment providers;
- provider-native refund/payment behavior beyond accepted provider-independent wallet foundations;
- all Phase `0.6.0+` owned behavior.

If the protected PasarGuard gate becomes available, follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` without exposing secrets. Otherwise continue autonomously with the `BUY-002` sequence above.
