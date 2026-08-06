# Phase 0.4 Plan Offering Foundation

Status: **Implementation verified — evidence-head CI pending**

## Accepted implementation

- SHA: `cf0971c068c46d8e71d4f7765fdda249d9a24471`
- CI run ID: `31061752803`
- CI run number: #816
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 236 tests, 1171 assertions
- test artifact: `test-evidence-31061752803`
- test artifact digest: `sha256:dbb2f16d84249eb3b6ddc539673c2e820293d9be1de353c7d1cc5825ef3f9a58`

## Verified boundary

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

## Security and consistency controls

- all authoritative monetary values are integer IRR;
- unlimited quantities use `NULL`, not magic sentinel values;
- `catalog.manage` is checked before execution and again inside the transaction;
- exact request replay returns the original receipt while payload conflicts fail closed;
- active and archived Offering configuration is immutable in both Service and MariaDB;
- child eligibility/protocol/capability/operation/package rows are writable only while the Offering is Draft;
- reverse database guards prevent later dependency changes from silently invalidating active or visible Offerings;
- history and audit contain structural IDs, counts, policies, state, version and configuration hashes, but exclude Persian/English labels and package names.

## Failure history and remediation

- run #813: Pint identified two formatting-only Domain files; the exact formatter output was applied;
- run #814: the application passed a non-schema key for the Target foreign key, and PHPStan identified four Collection count calls;
- the mapping was corrected to `panel_service_target_id`, and direct database counts replaced Collection counts while preserving locks and validation;
- run #816: every mandatory CI job passed.

No authorization check, database Trigger, lifecycle guard, immutability rule, dependency validation or test was removed or weakened during remediation.

## Retained artifacts

- preflight: `preflight-evidence-31061752803`, digest `sha256:7ace5bb8bed2ed7650fa77cbe2ca9a47b6e43c75c56a8536194387b38e85e541`
- secret scan: `gitleaks-results.sarif`, digest `sha256:29ba3a2ce28aba433f337ba868a1c4da4efe2a2fbc6a134f1246f3fb90794623`
- static: `static-evidence-31061752803`, digest `sha256:a1ff9ccba211f4a86fca32f1ce0fb3a13a5cdd4f340f8a4caeb243b8c0ebba35`
- dependency: `dependency-evidence-31061752803`, digest `sha256:7861b7f62405aa868813fc38dea7c96eb475ff66b035d66aad9174d3259fc712`
- tests: `test-evidence-31061752803`, digest `sha256:dbb2f16d84249eb3b6ddc539673c2e820293d9be1de353c7d1cc5825ef3f9a58`

## Intentionally excluded

- customer/agent price resolution, discounts, promotions and referral pricing;
- immutable Quote construction;
- ledger, payment, Order, provisioning or Service lifecycle;
- capacity reservation, route fallback and customer/system server selection execution;
- custom-plan calculation snapshots and trial eligibility execution;
- live Panel calls or provider adapters.

## Evidence-head gate

The commit containing this evidence must pass the same complete CI workflow. Issue #7 and PR #6 remain unchanged until that evidence-head run is Green.
