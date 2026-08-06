# Phase 0.4 Availability, Route Selection and Fallback Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Bounded sub-increment: `4b — Availability, Route Selection and Disclosed Compatible Fallback`  
Status: Implementation verified; evidence-head CI pending

| Requirement | Verified behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-002` | An Offering route policy links the Offering to its primary Server/Target and ordered alternate Server/Target routes; selection stores an immutable route and capacity snapshot | Route Domain/Application, migration | policy, selection and migration/full regression tests | `evidence/0.4.0/route-selection-fallback.md` |
| `CAT-004` | Requested/default Protocol Profile is selected only under the Offering policy and every configured route must support all Offering profiles | policy service, selector and operational verifier | customer/system mode and compatibility tests | same evidence |
| `CAT-008` | Runtime availability is derived from operational evidence plus authoritative Target capacity; fallback is only to compatible preconfigured routes in deterministic order with Persian disclosure | selector, verifier, capacity allocator, schema guards | full-capacity primary fallback and production fail-closed tests | same evidence |
| `ACL-002` | Route-policy mutation requires current `catalog.manage` authority and transaction-time revalidation | existing Catalog mutation executor and route-policy service | unauthorized policy mutation test | same evidence |
| `SEC-002` | Caller cannot assert tier/tags/agent status; authoritative actor state is read from DB; command keys are payload-bound; route selection and capacity hold are atomic and replay-safe | selector and existing capacity allocator | replay/conflict, immutable selection and eligibility paths | same evidence |
| `DAT-003` | FK-backed policy/routes/history/selections, uniqueness, checks, indexes and MariaDB Triggers enforce relational snapshots and immutability | `2026_08_06_001900_create_plan_offering_route_foundation.php` | migration and full MariaDB suite | same evidence |
| `QUA-001` | Requirements map to reviewed code, focused tests, mandatory full CI, actual counts, retained digests and independent evidence-head CI | all candidate files | CI run #826 and pending evidence-head CI | same evidence |

## Accepted implementation

- implementation SHA: `ca88704d34f7df68799d8673e30c3ff479529206`
- mandatory CI run ID: `31067121678`
- CI run number: #826
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 247 tests, 1228 assertions
- test artifact: `test-evidence-31067121678`
- test artifact digest: `sha256:90c32234ab6452cd0847513c820bc25094041d0e19653623b1189f6ea963af9d`

## Policy invariants

- one primary route at priority zero;
- fallback priorities are contiguous and deterministic;
- Target IDs are unique within a policy;
- primary Server/Target must match the Offering identity;
- fallback routes require Persian disclosure text;
- customer-selectable flags must match the Offering server-selection mode;
- every route must use non-archived Server/Target rows;
- every route Target must support every Offering Protocol Profile;
- every route Target must declare every required Offering/operation capability;
- policy configuration is mutable only while the Offering is Draft;
- history stores configuration hash, route count, fallback count, actor, reason and correlation ID.

## Runtime selection invariants

The selector derives actor state from `users`, `customer_profiles`, active tier and active tag assignments. Agent selection additionally requires an active agent profile. Caller input cannot override authoritative tier, tag or agent state.

The selector enforces:

- Offering audience;
- tier and any/all tag policy;
- fixed/customer/system Protocol Profile policy;
- fixed/customer/system/hybrid route selection policy;
- requested route membership and customer-selectability;
- Production operational verifier gates;
- authoritative capacity hold.

The selected route and its capacity hold are written in one MariaDB transaction. If a candidate is operational but has insufficient capacity, only the next configured compatible fallback is considered. A successful fallback snapshots its Persian disclosure.

## Production verifier

The Production binding is `DatabaseRouteOperationalVerifier`. It requires:

- active visible Offering;
- active listed Sales Server;
- active verified Service Target;
- active Panel Connection with successful connection-test state;
- Target verification evidence tied to the current Connection version;
- active Protocol Profile assigned to the Target;
- verified required Target capabilities.

Current Phase 0.4 Target foundations deliberately remain disabled/declared until provider adapters and capability discovery produce real evidence. Therefore Production route selection remains fail-closed now; the tests use a test-only verifier double solely to exercise deterministic selection and real MariaDB capacity reservation. Production dependency injection never binds that double.

## Replay and snapshot semantics

- one globally unique route-selection command key;
- payload hash binds Offering, actor, requested route/profile, units and expiry;
- exact replay returns the prior immutable selection and prior capacity snapshot;
- conflicting command-key reuse fails closed;
- one capacity reservation can back only one route selection;
- route selections cannot be updated or deleted;
- requested/selected route, Server, Target, Protocol Profile, units, fallback disclosure and capacity counters/versions are retained.

## Explicit boundary

This sub-increment completes the combined Issue #7 work package for capacity accounting, availability, selection and compatible fallback together with verified sub-increment `4a`.

It does not create:

- Quote, Order, payment, ledger or pricing resolution;
- provisioning job or remote Panel request;
- remote health discovery or adapter evidence;
- custom-plan calculation;
- trial eligibility or abuse policy.
