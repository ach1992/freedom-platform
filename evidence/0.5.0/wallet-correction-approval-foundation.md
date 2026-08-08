# Phase 0.5 Wallet Correction / Approval Foundation Evidence

**Status:** implementation-verified evidence candidate; exact evidence-head CI remains required before this bounded increment is accepted.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirement:** `WAL-005` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `ACL-001`, `ACL-002`, `SEC-002`, `QUA-001`.  
**Implementation head:** `fd3d579d9f38004310d7ea638e351813d2f46ef5`.  
**Implementation CI:** `31260299072` / `#1186` — success.  
**Traceability candidate:** `docs/55-phase-0.5-wallet-correction-traceability.md`.

## Bounded scope

This increment implements administrator wallet balance corrections as explicit immutable compensating ledger effects. It does not edit historical ledger entries, invent a provider settlement, substitute for a refund, bypass Payment Intent or Order state machines, or pull customer-facing Phase `0.6.0+` behavior forward.

A correction is bound to one owned active IRR wallet bucket, one positive integer amount, one credit/debit direction, one administrator, one required reason and explanatory note, and an optional bounded `ticket`, `order`, or `payment` reference.

## Production behavior

### Deterministic preview and confirmation

`WalletCorrectionService::preview()`:

- requires `wallet.corrections.create` authorization;
- validates an active owned `cash` or `promotional` liability account in IRR;
- derives current wallet balance only from finalized ledger entries plus active wallet holds;
- rejects debit corrections above current available balance;
- records a unique correction key and canonical payload hash for exact replay/conflict;
- snapshots ledger balance, active holds, available balance and deterministic post-correction balances;
- snapshots whether independent approval is required under the current correction policy;
- returns a SHA-256 confirmation token binding the immutable payload, authoritative balance snapshot, resulting balances and approval requirement;
- stores the preview as append-only history and records safe administrator audit metadata.

Reusing an existing correction key with the exact canonical payload returns the immutable preview as replay. Materially changed reuse fails closed.

### Approval policy and execution-time controls

Correction policy is configured under `wallet.corrections`:

- `dual_approval_threshold_irr` is integer IRR;
- `approval_ttl_seconds` is bounded to the existing sensitive-action approval lifetime contract.

Owners do not require an independent approval. For non-owner administrators, threshold `0` means every correction requires independent approval; otherwise amounts at or above the threshold require approval.

The `finance` role receives `wallet.corrections.create` and `wallet.corrections.large`. The large permission is configured as a critical permission requiring sensitive approval.

Large non-owner corrections reuse the existing `SensitiveActionApprovalService` and bind approval to:

- permission `wallet.corrections.large`;
- action `wallet.correction.execute`;
- target type `wallet_correction_preview`;
- exact preview ID;
- original requesting administrator;
- independent approver distinct from requester;
- one consumed execution fingerprint within expiry.

Execution locks the immutable preview, re-authorizes the requesting administrator, locks/revalidates the owned wallet account and fresh ledger/hold balance, rejects a stale preview, recomputes the current approval policy and fails closed if policy changed after preview. An approved correction consumes the sensitive action inside the financial transaction before the correction row is accepted.

Replay of an already committed approved correction requires the exact committed approval ID. Missing or conflicting approval input cannot be used to obtain a second accepted path.

### Compensating ledger effect

No prior balance or ledger row is edited or deleted.

Each accepted correction posts one finalized balanced `wallet_correction` ledger transaction against the exact target wallet and the dedicated system account `system.wallet.correction.offset`:

- credit correction: debit system correction offset, credit wallet;
- debit correction: debit wallet, credit system correction offset.

The correction stores immutable before/after ledger and available-balance evidence plus the accepted approval and ledger transaction links. Database guards reject mutation/deletion of accepted preview/correction history and verify that an inserted correction matches the immutable preview, the exact consumed approval when required, and the exact finalized two-entry compensating ledger effect.

## Executable verification

`tests/Feature/WalletCorrectionFoundationTest.php` contains **6 tests / 57 assertions** covering:

1. owner credit preview, confirmation, execution and exact replay;
2. compensating debit correction plus negative-available-balance prevention;
3. immutable/idempotent preview, confirmation mismatch and stale-preview rejection;
4. non-owner independent sensitive approval, self-approval rejection and atomic consumption;
5. configurable large-correction threshold boundary;
6. related-reference validation plus database update/delete guards.

`tests/Feature/WalletCorrectionContentionVerificationTest.php` contains **3 tests / 28 assertions** using independent PHP processes and a deterministic `READY` / `GO` barrier against real CI MariaDB:

1. two concurrent `700,000 IRR` debit corrections against a `1,000,000 IRR` wallet allow only one accepted effect and keep available balance non-negative;
2. concurrent duplicate execution produces one primary correction/ledger/audit effect plus one exact replay;
3. concurrent duplicate execution using one approved sensitive action consumes approval once and resolves to one primary effect plus one replay.

`tests/Feature/WalletCorrectionPolicyReplayTest.php` contains **2 tests / 13 assertions** covering:

1. execution fails closed when the configured large-correction approval policy changes after preview;
2. an approved correction replay requires the exact committed approval ID and rejects missing/conflicting approval input.

Dedicated correction verification therefore totals **11 tests / 98 assertions**, zero failures/errors/skips on the accepted implementation candidate.

## Exact implementation-head CI

Exact head `fd3d579d9f38004310d7ea638e351813d2f46ef5`, run `31260299072` / `#1186`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **371 tests / 2197 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31260299072`, ID `9022602206`, five expected files;
- GitHub uploader digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`;
- independently recalculated SHA-256: `476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Independent artifact inspection observed exactly:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

JUnit independently confirms the three correction suites as `6 / 57`, `3 / 28`, and `2 / 13`, all with zero failures/errors/skips. `test.log` records the complete `371 / 2197` suite.

## Safety conclusions

For this bounded implementation candidate:

- corrections compensate immutable history instead of rewriting ledger history;
- debit authorization uses fresh finalized-ledger and active-hold state under the wallet lock;
- concurrent debit corrections cannot drive accepted available balance below zero;
- preview/confirmation/policy state cannot silently drift into a different accepted financial effect;
- non-owner large corrections use existing independent sensitive-action approval with distinct approver and atomic consumption;
- exact replay cannot create a second correction, ledger effect or audit event;
- approved replay remains bound to the exact accepted approval ID;
- database guards independently verify preview, approval and compensating ledger linkage;
- safe audit records bounded metadata rather than secret/provider material.

## Explicit non-claims

This increment does **not** claim:

- `WAL-001` external wallet top-up or Payment Intent settlement;
- payment-provider capture/refund implementation or live provider acceptance;
- pricing/Quote, promotions/referrals or agent pricing;
- Order/provisioning/referral lifecycle ownership;
- a correction shortcut around accepted `WAL-004` refund rules or future payment/order state machines;
- customer/admin Telegram or HTTP UX for corrections;
- Phase `0.4.0` closure, Phase `0.5.0` closure or release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment acceptance remains a final-release gate.

The next bounded financial increment after evidence-head acceptance is Payment Intent plus `WAL-001` top-up settlement, reusing the accepted ledger/wallet idempotency and authority primitives without claiming provider live acceptance prematurely.
