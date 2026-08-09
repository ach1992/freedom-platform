# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Traceability

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Implementation evidence:** `evidence/0.5.0/agent-pricing-quote-integration.md`  
**Status:** Worker candidate; independent MASTER review/integration pending.

## Requirement mapping

| Requirement | Worker evidence | Boundary / non-claim |
|---|---|---|
| `AGT-005` most-specific agent pricing consumption | Agent Quote creation invokes accepted `AgentPricingService`; caller-supplied arbitrary agent overrides are rejected; matched/no-match/combination policy/history are persisted and tested | Does not modify accepted AgentPricing profile/rule management or selection semantics; no `AGT-003`/`AGT-004` purchase flow |
| `BUY-002` immutable Quote | Existing Quote creation/replay remains the persistence authority; agent resolution identity becomes part of immutable Quote snapshot; replay returns stored Quote before mutable re-resolution | No paid Order, checkout or purchase state machine |
| `PRO-001` compatibility | W-001 rule resolution **7/74** and W-002 reservation/release suites **18/80** remain green; successful agent Quote creates no promotion reservation | No Promotions schema/application modification and no reservation/redemption effect |
| `DAT-002` relational integrity | New Quote binding has FKs to accepted agent pricing resolution/profile/rule roots where applicable | Existing applied migrations remain unchanged |
| `DAT-003` uniqueness / immutable identity | Quote key and accepted resolver replay identities remain unique; Quote binds one agent pricing resolution; DB guard rejects forged/missing binding | No mutable reinterpretation of accepted Quote |
| `DAT-004` history/snapshotting | Stores resolution public ID/hash, agent profile, pricing profile public ID/code/version/hash, action, rule public ID/code/version/hash, override and `discount_combination_allowed` | Later rule/profile changes affect only future resolutions/Quotes |
| `SEC-001` bounded sensitive evidence | CI secret scan green; retained artifact bounded scan found no known secret-pattern match | No credentials/provider/live data introduced |
| `SEC-002` authorization / fail closed | Actor user is explicitly bound to agent Quote subject; non-agent/cross-user/suspended/stale-profile/invalid context fail closed | No Scheduler/Worker/provider authority model introduced |
| `QUA-001` exact evidence | Implementation SHA `73b02ebc7457a068d0d8416fc69d395342bd88df`, CI `31298291510` / `#1331`, all 5 mandatory jobs success, full **431/2701**, retained artifact ID `9033753890`, independent digest verified | Evidence-head CI remains a separate Worker gate until this candidate head passes |

## Application flow

For a new agent Quote, the bounded sequence is:

1. validate Quote key/user/Offering/correlation and typed agent pricing context;
2. fail if actor user differs from Quote subject;
3. calculate request payload identity including agent action/authority mode;
4. return exact existing Quote replay before current mutable pricing lookup;
5. for a new Quote, revalidate active customer/agent account and require agent context for an agent subject;
6. reject arbitrary caller-supplied agent override data;
7. read current agent profile code and call the accepted `AgentPricingService` with deterministic key bound to Quote key, user, Offering and action;
8. if no rule matches, keep Offering base price; if matched, take override amount/reference only from the accepted resolution;
9. reject positive discount if the accepted resolution snapshot forbids combination;
10. apply eligible discount after the selected base/override price, using integer IRR only;
11. persist immutable Quote configuration plus exact agent-pricing identity/provenance;
12. MariaDB verifies the inserted agent Quote binding against the accepted `agent_pricing_resolutions` row.

## Storage binding

Forward migration: `database/migrations/2026_08_09_003300_add_agent_pricing_binding_to_quotes.php`.

The nullable columns preserve existing non-agent/legacy Quote rows while new agent Quote inserts are fail-closed. The binding records:

- accepted agent-pricing resolution ID/public ID/configuration hash;
- agent profile ID snapshot;
- pricing profile root ID/public ID/code/version/configuration hash;
- pricing action;
- matched rule root ID/public ID/code/version/configuration hash when present;
- `discount_combination_allowed`.

The Quote's existing `override_source`, override reference and override amount continue to hold the pricing components consumed by BUY-002. The new MariaDB trigger cross-checks them with the accepted agent-pricing resolution and enforces matched/no-match shape. Existing Quote update/delete guards make the complete row immutable after insertion.

## Focused proof

Implementation JUnit on CI `#1331`:

- `AgentPricingQuoteIntegrationTest` — **6 tests / 73 assertions**;
- `QuotePricingSnapshotTest` — **8 / 68**;
- accepted `AgentPricingResolutionFoundationTest` — **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest` — **7 / 74**;
- W-002 reservation/release compatibility — **18 / 80** across its four MariaDB/contention suites;
- full repository — **431 / 2701**.

Covered agent Quote cases include authoritative matched override, explicit no-match fallback, discount combination allowed/disallowed, arbitrary override denial, cross-user/non-agent/suspended/stale-profile denial, exact replay/conflict without second resolution, later-rule historical stability and direct DB forgery/missing-binding rejection.

## Preserved compatibility

Customer, account and tier Quote behavior remains on the accepted BUY-002 path with formula version `buy-002-v1`. Agent Quotes use a distinct snapshot formula marker `buy-002-agent-pricing-v1` and include the accepted agent-pricing provenance.

The old BUY-002 regression that manually supplied an agent override has been converted to an authorization-denial assertion because such input is no longer authoritative after Issue #31. No other accepted customer/tier Quote semantic is widened.

## Scope exclusions

No changes are made to:

- `AgentPricingService` profile/rule management or resolver selection behavior;
- `app/Modules/Promotions/**` or promotion migrations/lifecycle;
- applied Quote `002930`, Promotions `003000/003100`, or Agent Pricing `003200` migrations;
- Payment Intent, providers, Wallet/ledger;
- Order aggregate/state machine;
- provisioning/Service lifecycle;
- workflows, runner/toolchain/project-control, provider-live/staging paths, PR #24 or `composer.lock`.

This record does not claim Phase 0.5 closure or independent acceptance. Exact evidence-head CI/artifact verification plus MASTER review remain required.
