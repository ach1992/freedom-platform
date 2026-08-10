# Project Status

This is the single human-readable current-state entry point. GitHub is authoritative for live PR/Issue/branch/CI state; Chat history is disposable.

**Last status review:** 2026-08-10  
**Target release:** `1.0.0`  
**Active phase:** `0.5.0 — Ledger, Pricing, Promotions and Payment Providers`  
**Authoritative phase Issue:** `#8`  
**Authoritative integration PR:** Draft PR `#6`  
**Integration branch:** `develop/v1.0.0-completion`  
**Base branch:** `main`  
**Current planning increment:** Phase 0.5 remaining-scope reconciliation

## Live-state rule

Before any implementation, review, merge, or project-control write, fetch PR `#6` and use its current `head_sha`. PR `#6` must stay Draft until final Version 1 acceptance.

Expected long-lived branch set is intentionally only:

- `main`;
- `develop/v1.0.0-completion`.

Any old `agent/*`, `ops/*`, or `safety/*` branch is obsolete after the August 10 cleanup and must not be used as a development base.

## Last verified product boundary

The last accepted product integration boundary is:

- implementation/evidence SHA `76ed06bbb272dbed971697587ca76f1785313dd3`;
- CI `31345041706` / `#1402` — all mandatory jobs passed;
- full suite **463 tests / 3057 assertions**;
- artifact `test-evidence-31345041706`, ID `9047072891`;
- SHA-256 `785c67bc39fba3565f861e35f6e2e599d6309420004ecf01329f840c2d86f6f2`;
- evidence `evidence/0.5.0/benefit-code-lifecycle.md`;
- traceability `docs/phase-0.5-benefit-code-lifecycle-traceability.md`.

Project-control/documentation cleanup commits may advance PR `#6` beyond this product SHA. Always inspect live CI before new product work.

## Phase 0.4 cleanup decision

Issue `#7` is closed as the completed Phase 0.4 implementation boundary. The temporary default-branch PasarGuard bootstrap PR `#24` is abandoned/closed and its `ops/*` and `safety/*` branches are obsolete.

Real deployed Marzban/PasarGuard acceptance was **not** claimed complete. Deployment-specific provider/version/capability/idempotency/uncertainty/Target-activation proof is deliberately carried to final release acceptance under Issue `#13`. Until then, source/offline/fake/harness evidence must not be described as production provider compatibility.

## Phase 0.5 accepted foundations

Accepted reusable work includes:

- immutable balanced Wallet/Ledger foundations, holds, transfer, refund and correction controls;
- Payment Intent / external cash-wallet top-up foundation;
- deterministic immutable `BUY-002` Quotes;
- promotion/referral pricing-rule resolution and promotion usage reservation/release foundations;
- most-specific agent-pricing resolution and immutable Quote consumption (`AGT-005` bounded integration, Issue `#31`, PR `#35`);
- secure `PRO-002` benefit-code lifecycle (Issue `#33`, PR `#38`);
- USDT BEP20 manual/Nobitex rate and immutable amount-quote foundation (Issue `#34`, PR `#37`).

The exact accepted boundary of each increment remains in its merged PR and repository evidence. Do not recreate accepted foundations merely because an old task document or branch exists.

## Remaining Phase 0.5 scope

Issue `#8` remains the authoritative phase backlog. Key unresolved areas include:

- remaining `PRO-001` successful-payment redemption/finalization and correct payment-driven release orchestration;
- `REF-001` reward lifecycle/effects;
- `PAY-001` gateway eligibility/routing requirement;
- remaining USDT work in Issue `#34`, including Tetherland contract completion and `USDT-003` transaction verification;
- card-to-card, gift-card, Zarinpal and NOWPayments Version 1 capabilities;
- genuine purchase-bound payment authority needed by later Order/provisioning work.

The previous W-005/PAY-001 implementation attempt was cancelled and never merged. Its old branches/PRs are audit history only. **The failed task is obsolete; the Version 1 `PAY-001` requirement is not satisfied or silently removed.** Any future PAY-001 implementation must be designed as a fresh bounded task from the current integration head.

## Phase boundaries

- Phase `0.5.0` / Issue `#8`: current finance/pricing/promotion/payment capability work.
- Phase `0.6.0` / Issue `#9`: Order, provisioning and Service lifecycle; do not pull these effects into Phase 0.5 merely to unblock a payment task.
- Phases `0.7.0`–`0.9.0`: Telegram UX, operations, hardening and release-candidate work.
- Phase `1.0.0` / Issue `#13`: final deployment/provider acceptance, package and owner handover.

The accepted Payment Intent is still bounded to `wallet_top_up`; it is not purchase authority. Browser return never proves capture and no paid provisioning occurs before authoritative settlement.

## Continuation entry point

Read only what is needed, in this order:

1. `AGENTS.md`;
2. this file;
3. `docs/project-status.json`;
4. `docs/README.md`;
5. live PR `#6` and exact-head CI;
6. Issue `#8` and the specific requirement/evidence files relevant to the next task;
7. `docs/development/multi-agent-orchestration.md` only when parallel Workers are actually being used.

Do not search numbered handoff/overlay documents for current state. Current task state belongs in GitHub and this file.
