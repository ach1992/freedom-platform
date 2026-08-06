# Phase 0.4 Plan Offering Foundation

Status: **Candidate — Unverified**

This bounded increment establishes Plan Offering as the server-specific commercial catalog aggregate. It is not complete until the exact implementation SHA and a separate evidence-head SHA pass the standard CI workflow.

## Candidate boundary

- Product plus optional Variant linked to one Sales Server and one Service Target;
- typed service-mode code with editable Persian/English labels;
- customer/system/hybrid server-selection policy;
- fixed/customer/system protocol-selection policy with exactly one default profile;
- signed integer IRR base price with no monetary float;
- positive duration and explicit nullable unlimited data/device limits;
- customer/agent/both audience, any-of customer tiers and explicit any/all tag matching;
- required Target capability declarations;
- typed renewal, add-data, add-days, combined, reset-usage and plan-change operation policies;
- typed renewal/add-on package definitions with integer IRR price and validated duration/data shape;
- Draft/Active/Archived lifecycle and hidden/visible availability;
- active/archived commercial configuration and child policies are immutable;
- exact replay, payload-conflict rejection, row locks, optimistic versions and FK-backed append-only histories;
- reverse dependency guards prevent Product, Variant, Server, Target, Profile or eligibility Tag changes from invalidating active/non-archived Offerings.

## Activation boundary

Activation and visibility require:

- active visible Product and active matching Variant when present;
- active listed Sales Server;
- active verified Service Target;
- active Panel Connection whose current version matches Target verification evidence;
- active Target-compatible Protocol Profiles;
- verified required and operation capabilities;
- active eligibility Tags.

Current Panel foundations deliberately block Service Target activation until provider adapters can produce verified evidence. Therefore this increment verifies fail-closed Offering activation, not an artificial operational success path.

## Intentionally excluded

- customer/agent price resolution, discounts, promotions and referral pricing;
- immutable Quote construction;
- ledger, payment, Order, provisioning or Service lifecycle;
- capacity reservation, route fallback and customer/system server selection execution;
- custom-plan calculation snapshots and trial eligibility execution;
- live Panel calls or provider adapters.

## Code and test map

- offering value objects under `app/Modules/Catalog/Domain/`;
- `PlanOfferingService` and bounded traits under `app/Modules/Catalog/Application/`;
- `CatalogServiceProvider` binding;
- `database/migrations/2026_08_06_001700_create_plan_offering_foundation.php`;
- `tests/Unit/Modules/Catalog/PlanOfferingDomainTest.php`;
- `tests/Feature/PlanOfferingFoundationMigrationTest.php`;
- `tests/Feature/PlanOfferingServiceTest.php`.

## Verification gate

Pending implementation SHA, mandatory CI, actual test/assertion counts, artifact digests and independent evidence-head CI. Issue #7 remains open and the Plan Offering checkbox remains unchecked while this candidate is unverified.
