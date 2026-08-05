# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records repository delivery state only; it does not replace that contract.

## Current position

- Active branch: `develop/v1.0.0-completion`.
- Pull request: Draft PR #6 targeting `main`.
- `main` remains unchanged.
- Active phase: `0.4.0 — Product model and ordering foundations`.
- Last completed phase: `0.3.0 — Identity, customer, agent, and access-control foundations`.
- Latest completed-phase evidence head: `f0f9fca6b16588d1e1b6557362758d2a9b1fffbf`.
- Latest completed-phase CI: `31035058551` — success.
- Automated suite at the Phase 0.3 boundary: 194 tests, 958 assertions.

## Completed phases

### Phase 0.1.0 — Platform and installer foundation

Status: completed and verified.

Delivered:

- Laravel application foundation and PHP 8.4 CI image;
- secure first-run installer and CLI finalization;
- immutable installer lock and bootstrap journal;
- Telegram webhook ingress, deduplication, queue handoff, retry and stale-recovery boundary;
- Persian-first locale and normalized Tehran business time;
- MariaDB/Redis disposable CI services;
- pinned GitHub Actions quality gates and evidence artifacts.

### Phase 0.2.0 — Telegram shell and interaction lifecycle

Status: completed and verified.

Delivered:

- deterministic callback codec and transition registry;
- stale/forged callback rejection and owner/account binding;
- message replacement and cleanup policy;
- Telegram rate-limit and queue back-pressure controls;
- restart-safe callback/session behavior;
- full mandatory CI evidence.

### Phase 0.3.0 — Identity, customer, agent, and access-control foundations

Status: completed and verified.

Delivered:

- Telegram-contact and SMS-OTP phone ownership verification;
- SMS provider adapters, rate limits, delivery evidence and fallback dispatch;
- customer status, tier, tag and append-only history services;
- agent application, review, approval, rejection, cooldown, reapplication and profile status lifecycle;
- multi-role administrator access with direct allow/deny/inherit overrides;
- short-lived sensitive-action approvals with independent approval and one-time consumption;
- database-enforced singleton Owner and protected two-party ownership transfer;
- encrypted national-ID, bank-card and full-name verification items with masked projections;
- privacy-safe customer My Account summary;
- administrator active, suspended and terminal revoked lifecycle with immediate authorization invalidation;
- request-fingerprint replay protection, row locking, sanitized audit evidence and mandatory reasons/correlation IDs.

Phase closure evidence:

- `evidence/0.3.0/phone-contact-verification.md`
- `evidence/0.3.0/otp-lifecycle.md`
- `evidence/0.3.0/sms-provider-adapters.md`
- `evidence/0.3.0/customer-management-transitions.md`
- `evidence/0.3.0/agent-application-lifecycle.md`
- `evidence/0.3.0/administrator-access-management.md`
- `evidence/0.3.0/sensitive-action-approvals.md`
- `evidence/0.3.0/owner-transfer.md`
- `evidence/0.3.0/encrypted-identity-items.md`
- `evidence/0.3.0/customer-account-summary.md`
- `evidence/0.3.0/administrator-lifecycle.md`
- `evidence/0.3.0/phase-closure.md`

Exit criteria:

- [x] identity and phone ownership boundaries are explicit and replay-safe;
- [x] customer commercial state is independent from administrator and agent context;
- [x] agent lifecycle and pricing-profile linkage are atomic and auditable;
- [x] administrator roles, direct overrides and explicit-deny precedence are verified;
- [x] sensitive actions use short-lived, bound, one-time approvals;
- [x] Owner transfer is separate, two-party, recently authenticated and atomic;
- [x] sensitive identity values are encrypted and never emitted in safe projections or audits;
- [x] administrator suspension/revocation invalidates authorization immediately;
- [x] mandatory CI, static analysis, database tests, dependency policy and secret scan pass.

## Active phase

### Phase 0.4.0 — Product model and ordering foundations

Status: in progress.

Planned dependency order:

1. categories, products, variants and auditable product state transitions;
2. options, add-ons and pricing-profile overrides;
3. customer order-intake state machine and normalized addresses;
4. quote snapshots and integer-rial monetary invariants;
5. payment instructions and proof lifecycle;
6. agent order-entry equivalence and acting-context audit.

No Phase 0.4 green claim is made until each increment receives a recorded mandatory CI run.

## Later phases

| Phase | Goal | Status |
|---|---|---|
| 0.5.0 | Fulfillment, delivery, finance, and reporting | not started |
| 0.6.0 | Content, banners, messaging, and operational controls | not started |
| 0.7.0 | Complete Telegram and web operator surfaces | not started |
| 0.8.0 | Legacy import, production hardening, and release | not started |

## Non-negotiable delivery controls

- no merge or direct write to `main` during completion work;
- one bounded increment and one standard CI workflow at a time;
- migrations are forward-only, reversible where practical and constraint-backed;
- monetary values use integer IRR;
- secrets and sensitive values never enter logs, audit safe-data or evidence;
- every privileged mutation is authorized at execution time and records actor, reason, correlation and request fingerprint;
- claims of completion require an exact commit SHA, CI run ID and test evidence.
