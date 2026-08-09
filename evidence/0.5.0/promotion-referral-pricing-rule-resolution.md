# Phase 0.5 Stored Promotion / Referral Pricing-Rule Resolution Evidence

**Status:** Worker evidence candidate; corrected implementation-head verification complete, MASTER review/integration pending.  
**Task Contract:** Issue `#25`, Worker `W-001`, Contract Revision `2`.  
**Parent Phase 0.5 Issue:** `#8`.  
**Requirements:** primary `PRO-001`, bounded `REF-001`; supporting `BUY-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `7b898fc3b643ad155f2f03f312e9a5d2d5265206`.  
**Worker branch:** `agent/25-promotion-rule-resolution`.  
**PR:** `#26` targeting `develop/v1.0.0-completion`.  
**Previous reviewed head:** `836adbfdf5380dae4854671f22de83cd3f0a3a69`.  
**Corrected implementation head:** `6724d570a73bd470d70b8d17f9e0422bec0c5257`.  
**Implementation CI:** `31285667745` / `#1266` — all five mandatory jobs successful.  
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
- administrator authorization/audit for rule-management mutation only;
- customer/agent-compatible resolution authorization bound to the legitimate subject user;
- MariaDB foreign keys, uniqueness, checks, hash verification, sequential-version guard, active-subject insert guard, and update/delete immutability triggers.

The resolver does not depend on database row order: it first derives the latest stored version per stable rule identity, then filters qualified rules, then requires exactly one highest-priority winner. A tie at the highest priority fails closed and stores no resolution.

## Resolution authority correction

Contract Revision 2 removes the administrator-only resolution boundary identified by MASTER review.

`PromotionRuleService::create()` and `revise()` continue to require the existing `AdministratorPermissionAuthorizer` with `promotions.rules.manage`, and their immutable rule-version audit provenance still stores the acting administrator plus reason/correlation data.

`PromotionRuleService::resolve()` instead accepts a typed `PromotionResolutionContext` containing the authenticated/legitimate user actor identity. It fails closed unless `actorUserId === request.userId` before replay or persistence. The service then validates from authoritative storage that the subject is an active `customer` or `agent` before creating a new resolution.

Resolution does not require or fabricate an administrator identity, does not call the administrator permission authorizer, and does not seed/use a `promotions.rules.resolve` administrator permission. `pricing_rule_resolutions` no longer requires or stores `resolved_by_administrator_id`; its actor-neutral provenance is the immutable pricing subject `user_id` plus the semantic request hash and historical resolved configuration identity.

The replay hash includes the pricing subject and semantic pricing inputs. Because subject equality is checked before replay, a different user cannot replay another subject's accepted resolution even with the same resolution key. Exact same-subject request reuse returns the immutable accepted result; materially changed same-key reuse conflicts.

No Scheduler/Worker/service authority is modeled as administrator authority by this correction.

## Monetary and historical semantics

All monetary values at this boundary are integer IRR. Percentage discounts use integer basis points and integer arithmetic only; no monetary float is introduced.

Rule mutation or disable creates a later immutable version. An already accepted resolution remains linked to the historical version/configuration identity it resolved against. Exact resolution-key replay returns that historical accepted result instead of re-running the mutable rule set.

The implementation deliberately does not alter accepted Quote persistence semantics. `QuoteService` continues to consume explicitly resolved pricing input. Future integration may consume the immutable rule-resolution identity without requiring this increment to reinterpret an already stored Quote.

## Database controls

The new branch-only forward migration owns three tables:

- `pricing_rules` — immutable stable identity;
- `pricing_rule_versions` — append-only definitions/configurations with administrator mutation provenance;
- `pricing_rule_resolutions` — immutable customer/agent-compatible matched or explicit no-match resolution records.

MariaDB enforces typed state/kind/discount/action shapes, amount and usage bounds, effective-window order, bounded JSON/hash form, parent/scope foreign keys, unique keys, rule/version identity, sequential versions, active customer/agent subject validity at resolution insert, immutable records, and resolution-to-version historical identity consistency.

The branch-only migration was corrected in place as explicitly allowed by Contract Revision 2. No migration present on the integration base was modified.

## Dedicated verification

`tests/Feature/PromotionRuleResolutionFoundationTest.php` contains **7 tests / 74 assertions**, zero failures/errors/skips in retained JUnit evidence. It covers:

1. fixed discount, authorized customer resolution, audience plus tier/tag/offering/product/server/action qualification, exact mutation replay/conflict, and absence of ledger/payment side effects;
2. percentage basis-point arithmetic, minimum/cap, effective window, total/per-user observed-use inputs, first-purchase input, referral source identity, and explicit no-match cases;
3. explicit priority precedence and fail-closed equal-priority ambiguity without persisted result;
4. exact resolution replay/conflict and historical stability after a later disabled rule version;
5. customer and agent subject resolution success, cross-user denial before replay, and preserved administrator rule-management create/revise allow/deny;
6. invalid configuration and explicit free-order policy input failing closed;
7. MariaDB uniqueness, foreign-key, check/hash, active-subject, absent administrator-resolution column, and immutability guards.

The accepted `QuotePricingSnapshotTest` remains unchanged and passed in the same exact implementation run at **8 tests / 63 assertions**, preserving accepted `BUY-002` behavior.

## Exact corrected implementation-head verification

Exact Worker implementation head `6724d570a73bd470d70b8d17f9e0422bec0c5257`, PR run `31285667745` / `#1266`:

- Repository preflight — success;
- Secret scan — success;
- PHP static quality — success, including Pint and static/repository policy;
- MariaDB and Redis tests — success;
- Dependency and license policy — success;
- full suite — **399 tests / 2429 assertions**, zero failures/errors/skips;
- dedicated promotion/referral pricing-rule suite — **7 / 74**;
- unchanged Quote pricing regression suite — **8 / 63**;
- runner — `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12`;
- retained artifact — `test-evidence-31285667745`, ID `9029733870`, retention `30` days, uploaded size `119611` bytes;
- uploader SHA-256 — `3657ac08fbc1ef75d413018e27c3950f4f58bfbe4e7c8bd13be9b1498fcc3f1c`;
- independently downloaded and recalculated SHA-256 — `3657ac08fbc1ef75d413018e27c3950f4f58bfbe4e7c8bd13be9b1498fcc3f1c`.

Independent artifact inspection found exactly five retained files: JUnit XML, test log, Clover coverage XML, compose state, and compose log. A bounded scan found no authorization-header, private-key-header, protected-provider-secret pattern, or database/Redis password-assignment pattern. No real credential or protected provider secret was requested or used by this increment.

Run `31285597971` / `#1265` is diagnostic only: functional source changes had been pushed, but Pint detected a missing final newline in the branch-only promotion migration. The verified implementation head above contains only that formatting correction after the corrected behavior/tests.

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
- actual Quote orchestration/integration consuming this resolution; accepted Quote persistence semantics are unchanged;
- provisioning or Service lifecycle;
- PasarGuard/Marzban live operations or Target activation;
- PR `#24` bootstrap/safety behavior;
- Phase `0.4.0`, Phase `0.5.0`, or release completion.

Observed total/per-user use counts are inputs to this bounded qualification layer. This increment does not create authoritative redemption counters or reservation concurrency, so those future effects require a separate contention-focused task and evidence.

## Remaining risk / continuation

`FIN-18` is reduced only for deterministic stored rule definition/resolution, legitimate customer/agent subject authority, and historical identity. Duplicate reservation/redemption/reward effects remain unproven because this Task Contract intentionally excludes those state transitions and counters.

MASTER must independently review PR `#26`, its exact HEAD/diff, CI, retained artifacts, evidence, and interaction with the current integration head. The Worker must not merge the PR. Exact evidence-head CI and artifact details are recorded durably on Issue `#25` and PR `#26` after this evidence commit passes the mandatory Worker gates.
