# Phase 0.5 Wallet Correction / Approval Foundation Evidence

**Status:** accepted bounded Phase `0.5.0` foundation after exact implementation-head and exact evidence-head verification.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` protected provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirement:** `WAL-005` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `ACL-001`, `ACL-002`, `SEC-002`, `QUA-001`.  
**Implementation head:** `fd3d579d9f38004310d7ea638e351813d2f46ef5`.  
**Implementation CI:** `31260299072` / `#1186` — success.  
**Evidence head:** `ef5504081a42687eb712e9cd47306cd9dcc9a864`.  
**Evidence CI:** `31260549403` / `#1188` — success.  
**Traceability:** `docs/55-phase-0.5-wallet-correction-traceability.md`.

## Bounded scope

This increment implements administrator wallet balance corrections as explicit immutable compensating ledger effects. It does not edit historical ledger entries, invent provider settlement evidence, substitute for a refund, bypass Payment Intent or Order state machines, or pull customer-facing Phase `0.6.0+` behavior forward.

A correction is bound to one owned active IRR wallet bucket, one positive integer amount, one credit/debit direction, one administrator, one required reason and explanatory note, and an optional bounded `ticket`, `order`, or `payment` reference.

## Production behavior

`WalletCorrectionService::preview()` requires current `wallet.corrections.create` authorization, validates an active owned `cash` or `promotional` IRR liability account, derives balance from finalized ledger entries plus active wallet holds, rejects an over-debit, records a unique correction key and canonical payload hash, snapshots before/resulting balances and approval policy, and returns a SHA-256 confirmation token binding the immutable preview. Exact key/payload reuse is replay; material reuse conflict fails closed.

Correction policy uses integer-IRR `wallet.corrections.dual_approval_threshold_irr` and bounded `approval_ttl_seconds`. Owners do not require independent approval. Non-owner corrections selected by policy require `wallet.corrections.large`, which is critical and approval-required. The existing `SensitiveActionApprovalService` binds the approval to permission `wallet.corrections.large`, action `wallet.correction.execute`, target `wallet_correction_preview` plus exact preview ID, original requester, distinct approver, expiry and one consumed execution fingerprint.

Execution locks the immutable preview, binds it to its requester, re-authorizes `wallet.corrections.create` inside the execution transaction, locks/revalidates the wallet and fresh balance/holds, rejects stale previews, recomputes the current approval policy and rejects policy drift. Approved execution consumes the matching sensitive action in the same outer financial transaction. Replay of an approved correction requires the exact committed approval ID.

No prior balance or ledger row is edited or deleted. Each accepted correction posts one finalized balanced `wallet_correction` ledger transaction against the target wallet and `system.wallet.correction.offset`:

- credit correction: debit system correction offset, credit wallet;
- debit correction: debit wallet, credit system correction offset.

Database guards reject mutation/deletion of accepted preview/correction history and verify exact immutable-preview linkage, exact consumed approval when required, and the exact finalized two-entry compensating ledger effect.

## Dedicated verification

`tests/Feature/WalletCorrectionFoundationTest.php` — **6 tests / 57 assertions**:

1. owner credit preview, confirmation, execution and exact replay;
2. compensating debit correction plus negative-available-balance prevention;
3. immutable/idempotent preview, confirmation mismatch and stale-preview rejection;
4. non-owner independent approval, self-approval rejection and atomic consumption;
5. configurable large-correction threshold boundary;
6. related-reference validation plus database update/delete guards.

`tests/Feature/WalletCorrectionContentionVerificationTest.php` — **3 tests / 28 assertions**, using independent PHP processes and a deterministic `READY` / `GO` barrier against CI MariaDB:

1. two concurrent `700,000 IRR` debits against a `1,000,000 IRR` wallet permit only one effect and retain non-negative availability;
2. concurrent duplicate execution produces one correction/ledger/audit effect plus one exact replay;
3. concurrent duplicate execution using one approved sensitive action consumes it once and resolves to one primary effect plus one replay.

`tests/Feature/WalletCorrectionPolicyReplayTest.php` — **2 tests / 13 assertions**:

1. execution fails closed when large-correction approval policy changes after preview;
2. approved replay requires the exact committed approval ID and rejects missing/conflicting approval input.

Dedicated `WAL-005` verification totals **11 tests / 98 assertions**, zero failures/errors/skips on both exact verified heads.

## Exact implementation-head verification

Exact implementation head `fd3d579d9f38004310d7ea638e351813d2f46ef5`, run `31260299072` / `#1186`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **371 tests / 2197 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12`;
- artifact `test-evidence-31260299072`, ID `9022602206`, five expected files;
- uploader and independently recalculated SHA-256: `476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

## Exact evidence-head verification

Exact evidence head `ef5504081a42687eb712e9cd47306cd9dcc9a864`, run `31260549403` / `#1188`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **371 tests / 2197 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12`;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- GitHub uploader digest: `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- independently downloaded/recalculated SHA-256: `ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`.

Independent artifact inspection found exactly five files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

Independent JUnit inspection confirms the correction suites as `6 / 57`, `3 / 28`, and `2 / 13`, all with zero failures/errors/skips. The full suite is `371 / 2197`.

## Accepted safety conclusions

For this bounded `WAL-005` foundation:

- corrections compensate immutable history rather than rewriting it;
- debit authorization uses fresh finalized-ledger and active-hold state under the wallet lock;
- concurrent debit corrections cannot drive accepted available balance below zero;
- preview/confirmation/policy state cannot silently drift into a different accepted financial effect;
- non-owner large corrections use existing independent approval with distinct approver and atomic consumption;
- exact replay cannot create a second correction, ledger effect or audit event;
- approved replay remains bound to the exact accepted approval ID;
- database guards independently verify preview, approval and compensating-ledger linkage;
- audit evidence contains bounded safe metadata rather than provider secrets.

## Explicit non-claims

This acceptance does **not** claim:

- `WAL-001` external wallet top-up or Payment Intent settlement;
- provider-native capture/refund implementation or live provider acceptance;
- pricing/Quote, promotions/referrals or agent pricing;
- Order/provisioning/service lifecycle ownership;
- customer/admin Telegram or HTTP UX for corrections;
- Phase `0.4.0` closure, Phase `0.5.0` closure or release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment acceptance remains a final-release gate.

The next bounded financial increment is Payment Intent plus `WAL-001` external cash-wallet top-up settlement, reusing the accepted ledger/wallet idempotency and authority primitives without claiming provider live acceptance prematurely.
