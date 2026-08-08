# Phase 0.5 Stored Promotion / Referral Pricing-Rule Resolution Evidence

**Status:** Worker evidence candidate; implementation-head verification complete, MASTER review/integration pending.  
**Task Contract:** Issue `#25`, Worker `W-001`, Contract Revision `1`.  
**Parent Phase 0.5 Issue:** `#8`.  
**Requirements:** primary `PRO-001`, bounded `REF-001`; supporting `BUY-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `7b898fc3b643ad155f2f03f312e9a5d2d5265206`.  
**Worker branch:** `agent/25-promotion-rule-resolution`.  
**PR:** `#26` targeting `develop/v1.0.0-completion`.  
**Implementation head:** `2be331fd4fe51ef4f7ee58ca2a82a178128bba09`.  
**Implementation CI:** `31284018788` / `#1256` — all five mandatory jobs successful.  
**Traceability:** `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`.

## Bounded behavior implemented

This increment establishes the first provider-independent stored promotion/referral pricing-rule definition, qualification, and resolution layer after accepted `BUY-002` Quote persistence.

The production boundary provides:

- stable typed rule identity with `promotion|referral` kind;
- append-only immutable configuration versions with explicit `draft|active|disabled|archived` state;
- fixed integer-IRR or integer basis-point percentage discount inputs;
- integer-IRR minimum-order and optional maximum-discount cap;
- optional effective start/end window;
- total/per-user observed-use limits as bounded definition/qualification inputs only;
- first-purchase input;
- customer/agent/both audience qualification;
- optional current tier, customer tag, Plan Offering, Product, Sales Server, and action scope;
- bounded referral pricing-source identity/input without reward execution;
- explicit priority precedence and fail-closed equal-priority ambiguity;
- explicit immutable no-match result with zero discount;
- immutable accepted resolution carrying historical rule ID/code/kind/version/configuration hash plus a bounded resolution snapshot/hash;
- exact mutation/resolution replay and conflicting-key rejection;
- execution-time administrator authorization;
- MariaDB foreign keys, uniqueness, checks, hash verification, sequential-version guard, and update/delete immutability triggers.

The resolver does not depend on database row order: it first derives the latest stored version per stable rule identity, then filters qualified rules, then requires exactly one highest-priority winner. A tie at the highest priority fails closed and stores no resolution.

## Monetary and historical semantics

All monetary values at this boundary are integer IRR. Percentage discounts use integer basis points and integer arithmetic only; no monetary float is introduced.

Rule mutation or disable creates a later immutable version. An already accepted resolution remains linked to the historical version/configuration identity it resolved against. Exact resolution-key replay returns that historical accepted result instead of re-running the mutable rule set.

The implementation deliberately does not alter accepted Quote persistence semantics. `QuoteService` continues to consume explicitly resolved pricing input. Future integration may consume the immutable rule-resolution identity without requiring this increment to reinterpret an already stored Quote.

## Authorization and database controls

`PromotionRuleService` requires current administrator authorization through the existing `AdministratorPermissionAuthorizer` service boundary:

- `promotions.rules.manage` for create/revise;
- `promotions.rules.resolve` for resolution.

The access seeder grants bounded permissions to existing owned roles; owner semantics remain those of the accepted authorization foundation.

The new forward-only migration owns three tables:

- `pricing_rules` — immutable stable identity;
- `pricing_rule_versions` — append-only immutable definitions/configurations;
- `pricing_rule_resolutions` — immutable accepted matched or explicit no-match resolution records.

MariaDB enforces typed state/kind/discount/action shapes, amount and usage bounds, effective-window order, bounded JSON/hash form, parent/scope foreign keys, unique keys, rule/version identity, sequential versions, immutable records, and resolution-to-version historical identity consistency.

No existing applied migration is modified.

## Dedicated verification

`tests/Feature/PromotionRuleResolutionFoundationTest.php` contains **7 tests / 60 assertions**, zero failures/errors/skips in retained JUnit evidence. It covers:

1. fixed discount, audience plus tier/tag/offering/product/server/action qualification, exact mutation replay/conflict, and absence of ledger/payment side effects;
2. percentage basis-point arithmetic, minimum/cap, effective window, total/per-user observed-use inputs, first-purchase input, referral source identity, and explicit no-match cases;
3. explicit priority precedence and fail-closed equal-priority ambiguity without persisted result;
4. exact resolution replay/conflict and historical stability after a later disabled rule version;
5. execution-time authorization allow/deny for seeded role permissions;
6. invalid configuration and explicit free-order policy input failing closed;
7. MariaDB uniqueness, foreign-key, check/hash, and immutability guards.

The accepted `QuotePricingSnapshotTest` remains unchanged and passed in the same exact implementation run at **8 tests / 63 assertions**, preserving the accepted `BUY-002` behavior.

## Exact implementation-head verification

Exact Worker implementation head `2be331fd4fe51ef4f7ee58ca2a82a178128bba09`, PR run `31284018788` / `#1256`:

- Repository preflight — success;
- Secret scan — success;
- PHP static quality — success, including Pint and static/repository policy;
- MariaDB and Redis tests — success;
- Dependency and license policy — success;
- full suite — **399 tests / 2415 assertions**, zero failures/errors/skips;
- dedicated promotion/referral pricing-rule suite — **7 / 60**;
- unchanged Quote pricing regression suite — **8 / 63**;
- runner — `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12`;
- retained artifact — `test-evidence-31284018788`, ID `9029282011`, retention `30` days, uploaded size `119499` bytes;
- uploader SHA-256 — `ebf72d8ea7822895cea0d306c866358f4d049201503895a13dce87fcce000fae`;
- independently downloaded and recalculated SHA-256 — `ebf72d8ea7822895cea0d306c866358f4d049201503895a13dce87fcce000fae`.

Independent artifact inspection found exactly five retained files: JUnit XML, test log, Clover coverage XML, compose state, and compose log. A bounded scan of the retained artifact found no bearer/basic authorization header, private-key header, or obvious secret-assignment pattern. No real credential or protected provider secret was requested or used by this increment.

Earlier run `31283920931` / `#1255` is diagnostic only: it exposed one Pint docblock-spacing defect. Head `2be331fd4fe51ef4f7ee58ca2a82a178128bba09` contains the formatting-only correction and run `#1256` is the implementation verification source of truth.

## Explicit non-claims

This Worker evidence does **not** prove or implement:

- promotion reservation, redemption, release, or contention counters;
- complete free-order execution;
- `PRO-002` gift codes;
- referral reward payout, pending/release/reversal execution, or referral notifications;
- `AGT-005` most-specific agent pricing;
- `PAY-001` payment-method eligibility or payment-method adjustment;
- payment provider implementation, provider-native refund, live provider credential use, or provider mutation;
- wallet debit, credit, hold, correction, refund, or top-up execution;
- paid Order state machine or purchase execution;
- provisioning or Service lifecycle;
- PasarGuard/Marzban live operations or Target activation;
- PR `#24` bootstrap/safety behavior;
- Phase `0.4.0`, Phase `0.5.0`, or release completion.

Observed total/per-user use counts are inputs to this bounded qualification layer. This increment does not create authoritative redemption counters or reservation concurrency, so those future effects require a separate contention-focused task and evidence.

## Remaining risk / continuation

`FIN-18` is reduced only for deterministic stored rule definition/resolution and historical identity. Duplicate reservation/redemption/reward effects remain unproven because this Task Contract intentionally excludes those state transitions and counters.

MASTER must independently review PR `#26`, its exact HEAD/diff, CI, retained artifacts, evidence, and interaction with the current integration head. The Worker must not merge the PR. Exact evidence-head CI and artifact details are recorded durably on Issue `#25` and PR `#26` after this evidence commit passes the mandatory Worker gates.
