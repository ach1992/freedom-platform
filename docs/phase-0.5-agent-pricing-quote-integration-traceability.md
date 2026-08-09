# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Traceability

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Corrected implementation evidence:** `evidence/0.5.0/agent-pricing-quote-integration.md`  
**Status:** Worker correction candidate; independent MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction mapping

MASTER review of reviewed HEAD `ccdc00e8207bbb389faac4d61e67331943517dfc` found that exact agent-priced Quote replay returned an existing immutable Quote before current subject eligibility was reauthorized. The correction stays within Contract Revision 1 and changes only the Quote replay authorization boundary plus focused tests/evidence.

For an existing Quote with `override_source=agent`, exact replay now validates the immutable stored request/Quote and then, before return:

1. requires the supplied actor to remain the stored Quote subject;
2. locks/reads the current `users` row and requires `account_status=active` plus `account_type=agent`;
3. locks/reads the current `agent_profiles` row and requires `status=active`;
4. requires current `agent_profiles.pricing_profile_code` to exactly equal the stored `agent_pricing_profile_code_snapshot`;
5. fails closed on missing/suspended/invalid/mismatched state.

This authorization step never calls `AgentPricingService::resolve()` and never recalculates the historical override, discount, matched rule, pricing-profile/rule versions, configuration hashes or final amount. Customer/tier/non-agent replay remains on the accepted BUY-002 behavior.

## Requirement mapping

| Requirement | Corrected Worker evidence | Boundary / non-claim |
|---|---|---|
| `AGT-005` most-specific agent pricing consumption | New agent Quote creation invokes accepted `AgentPricingService`; caller-supplied arbitrary agent overrides are rejected; matched/no-match/combination policy/history are persisted and tested. Exact accepted agent-priced replay performs current subject/profile-code eligibility checks but consumes only the immutable stored pricing result. | Does not modify accepted AgentPricing profile/rule management or selection semantics; replay does not re-resolve; no `AGT-003`/`AGT-004` purchase flow |
| `BUY-002` immutable Quote | Existing Quote persistence remains authoritative; agent resolution identity is part of immutable Quote snapshot. Replay validates stored payload/snapshot, reauthorizes current agent eligibility, then returns the historical Quote without pricing mutation. | No paid Order, checkout or purchase state machine |
| `PRO-001` compatibility | W-001 rule resolution **7/74** and W-002 reservation/release suites **18/80** remain green; successful agent Quote creates no promotion reservation | No Promotions schema/application modification and no reservation/redemption effect |
| `DAT-002` relational integrity | Existing forward Quote binding has FKs to accepted agent pricing resolution/profile/rule roots where applicable | No correction migration; existing applied migrations remain unchanged |
| `DAT-003` uniqueness / immutable identity | Quote key and accepted resolver replay identities remain unique; Quote binds one agent pricing resolution; DB guard rejects forged/missing binding. Replay denial creates neither a second Quote nor a second resolution. | No mutable reinterpretation of accepted Quote |
| `DAT-004` history/snapshotting | Stores resolution public ID/hash, agent profile, pricing profile public ID/code/version/hash, action, rule public ID/code/version/hash, override and `discount_combination_allowed`. Version-revision replay proof retains original profile/rule versions while later Quotes use later versions. | Current eligibility may deny replay, but accepted historical pricing bytes are never rewritten or recalculated |
| `SEC-001` bounded sensitive evidence | Corrected implementation CI secret scan green; retained artifact bounded scan found no authorization-header token, private-key block or obvious long credential assignment | No credentials/provider/live data introduced |
| `SEC-002` authorization / fail closed | Actor user is explicitly bound to agent Quote subject. Fresh creation uses accepted active agent/profile checks. Exact `override_source=agent` replay additionally requires current active agent user, active agent profile and exact current profile-code match to stored snapshot before return. Suspension/mismatch tests prove fail-closed replay with no new effect. | No Scheduler/Worker/provider authority model introduced; customer/tier/non-agent replay semantics unchanged |
| `QUA-001` exact evidence | Corrected implementation SHA `7878bd213c62c3e8d436b2eaeb74d223061b3fb2`, CI `31312232236` / `#1348`, all 5 mandatory jobs success, full **433/2718**, focused **8/90**, retained artifact ID `9037731851`, independent digest verified | Exact corrected evidence-head CI remains a separate Worker gate until the final docs head passes |

## Corrected application flow

For a new agent Quote, the bounded sequence remains:

1. validate Quote key/user/Offering/correlation and typed agent pricing context;
2. fail if actor user differs from Quote subject;
3. calculate request payload identity including agent action/authority mode;
4. if no exact existing Quote exists, revalidate active account and require agent context for an agent subject;
5. reject arbitrary caller-supplied agent override data;
6. read current agent profile code and call the accepted `AgentPricingService` with deterministic key bound to Quote key, user, Offering and action;
7. if no rule matches, keep Offering base price; if matched, take override amount/reference only from the accepted resolution;
8. reject positive discount if the accepted resolution snapshot forbids combination;
9. apply eligible discount after the selected base/override price, using integer IRR only;
10. persist immutable Quote configuration plus exact agent-pricing identity/provenance;
11. MariaDB verifies the inserted agent Quote binding against the accepted `agent_pricing_resolutions` row.

For an exact existing Quote replay:

1. lock/read the Quote by key and validate exact request payload identity;
2. reconstruct/validate the immutable stored Quote and its agent-pricing binding;
3. when `override_source=agent`, perform the current user/agent-profile/profile-code authorization checks described above;
4. return the historical Quote only after those checks pass;
5. never invoke the mutable agent-pricing resolver or recompute pricing for replay.

The duplicate-key race fallback repeats the same locked replay authorization sequence rather than bypassing it.

## Storage binding

Forward migration remains `database/migrations/2026_08_09_003300_add_agent_pricing_binding_to_quotes.php`; the correction does not edit it or any applied migration.

The nullable columns preserve existing non-agent/legacy Quote rows while new agent Quote inserts are fail-closed. The binding records:

- accepted agent-pricing resolution ID/public ID/configuration hash;
- agent profile ID snapshot;
- pricing profile root ID/public ID/code/version/configuration hash;
- pricing action;
- matched rule root ID/public ID/code/version/configuration hash when present;
- `discount_combination_allowed`.

The Quote's existing `override_source`, override reference and override amount continue to hold the pricing components consumed by BUY-002. The existing MariaDB trigger cross-checks them with the accepted agent-pricing resolution and enforces matched/no-match shape. Existing Quote update/delete guards make the complete row immutable after insertion.

## Corrected focused proof

Corrected implementation JUnit on CI `#1348`:

- `AgentPricingQuoteIntegrationTest` — **8 tests / 90 assertions**;
- `QuotePricingSnapshotTest` — **8 / 68**;
- accepted W-003 `AgentPricingResolutionFoundationTest` — **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest` — **7 / 74**;
- W-002 reservation/release compatibility — **18 / 80** across its four MariaDB/contention suites;
- full repository — **433 / 2718**.

Corrected agent Quote coverage adds:

- exact replay denial after current agent user suspension, with no new Quote/resolution;
- exact replay denial after current agent-profile suspension, with no new Quote/resolution;
- exact replay denial after current profile-code mismatch while the stored historical Quote remains unchanged;
- exact replay after pricing-profile and pricing-rule version revision with the same active/current profile code, proving the historical Quote keeps its original pricing-profile/rule versions and resolution identity without re-pricing;
- later new Quote consumption of the later pricing-profile/rule versions.

Existing coverage remains for authoritative matched override, explicit no-match fallback, discount combination allowed/disallowed, arbitrary override denial, cross-user/non-agent/suspended/stale-profile denial for fresh effects, exact replay/conflict identity, direct DB forgery/missing-binding rejection and Quote immutability.

## Preserved compatibility

Customer, account and tier Quote behavior remains on the accepted BUY-002 path with formula version `buy-002-v1`. Agent Quotes use `buy-002-agent-pricing-v1` and include accepted agent-pricing provenance.

The old BUY-002 regression that manually supplied an agent override remains converted to an authorization-denial assertion because such input is no longer authoritative after Issue #31. No other accepted customer/tier Quote semantic is widened.

## Scope exclusions

No correction changes are made to:

- `AgentPricingService` profile/rule management or resolver selection behavior;
- `app/Modules/Promotions/**` or promotion migrations/lifecycle;
- applied Quote `002930`, Promotions `003000/003100`, or Agent Pricing `003200` migrations;
- Payment Intent, providers, Wallet/ledger;
- Order aggregate/state machine;
- provisioning/Service lifecycle;
- workflows, runner/toolchain/project-control, provider-live/staging paths, PR #24 or `composer.lock`.

This record does not claim Phase 0.5 closure or independent acceptance. Exact corrected evidence-head CI/artifact verification plus MASTER correction review remain required, and PR #35 remains Draft for that review.
