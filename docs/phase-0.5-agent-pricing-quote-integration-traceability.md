# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Traceability

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Previous reviewed HEAD:** `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6`  
**Corrected implementation evidence:** `evidence/0.5.0/agent-pricing-quote-integration.md`  
**Status:** Worker correction candidate; independent MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction mapping

MASTER correction review of reviewed HEAD `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6` found that the replay authorization guard was activated only for `override_source=agent`. Contract Revision 1 also permits authoritative agent-pricing no-match Quotes that carry a persisted agent-pricing resolution/binding while falling back to `override_source=none` and Offering base price. Those Quotes therefore still bypassed current subject/profile reauthorization on replay.

The correction stays within Contract Revision 1 and changes only the Quote replay-bound detection plus focused tests/evidence. Replay authorization is now required whenever the reconstructed Quote carries persisted `agentPricing` provenance, regardless of whether the accepted resolution matched an override rule.

For every agent-pricing-bound exact replay, before return the shared guard:

1. requires the supplied actor to remain the stored Quote subject;
2. locks/reads the current `users` row and requires `account_status=active` plus `account_type=agent`;
3. locks/reads the current `agent_profiles` row and requires `status=active`;
4. requires current `agent_profiles.pricing_profile_code` to exactly equal the stored agent-pricing profile-code snapshot;
5. fails closed on missing/suspended/invalid/mismatched state.

This authorization step never calls `AgentPricingService::resolve()` and never recalculates the historical base price, override, discount, matched rule, profile/rule versions, configuration hashes, resolution identity or final amount. Customer/tier/non-agent replay remains on accepted BUY-002 behavior because those Quotes carry no agent-pricing binding.

## Requirement mapping

| Requirement | Corrected Worker evidence | Boundary / non-claim |
|---|---|---|
| `AGT-005` most-specific agent pricing consumption | New agent Quote creation invokes accepted `AgentPricingService`; caller-supplied arbitrary agent overrides are rejected; matched/no-match/combination policy/history are persisted and tested. Exact replay of any Quote carrying agent-pricing binding reauthorizes current subject/profile-code eligibility but consumes only immutable stored pricing. | Does not modify accepted AgentPricing profile/rule management or selection semantics; replay does not re-resolve; no `AGT-003`/`AGT-004` purchase flow |
| `BUY-002` immutable Quote | Existing Quote persistence remains authoritative; agent resolution identity is part of immutable Quote snapshot even for no-match/base fallback. Replay validates stored payload/snapshot, reauthorizes current agent eligibility for binding-backed Quotes, then returns historical pricing without mutation. | No paid Order, checkout or purchase state machine |
| `PRO-001` compatibility | W-001 rule resolution **7/74** and W-002 reservation/release suites **18/80** remain green; successful agent Quote creates no promotion reservation | No Promotions schema/application modification and no reservation/redemption effect |
| `DAT-002` relational integrity | Existing forward Quote binding has FKs to accepted agent pricing resolution/profile/rule roots where applicable; no-match retains resolution/profile binding with nullable matched-rule fields | No correction migration; existing applied migrations remain unchanged |
| `DAT-003` uniqueness / immutable identity | Quote key and accepted resolver identities remain unique; Quote binds one agent-pricing resolution; DB guard rejects forged/missing binding. Replay denial creates neither a second Quote nor a second resolution. | No mutable reinterpretation of accepted Quote |
| `DAT-004` history/snapshotting | Stores resolution public ID/hash, agent profile, pricing profile public ID/code/version/hash, action, nullable rule identity/version/hash, override when matched and `discount_combination_allowed`. No-match version-revision replay retains original profile/resolution/hash/prices while current version rows may advance. | Current eligibility may deny replay, but accepted historical pricing bytes are never rewritten or recalculated |
| `SEC-001` bounded sensitive evidence | Corrected implementation CI secret scan green; retained artifact bounded scan found no authorization-header token, private-key block or obvious long credential assignment | No credentials/provider/live data introduced |
| `SEC-002` authorization / fail closed | Actor user is bound to Quote subject. Exact replay authorization is keyed by persisted agent-pricing binding, covering both matched override and no-match/base fallback. Current active agent user, active agent profile and exact current profile-code match are required before return; suspension/mismatch tests prove fail-closed replay with no new effect. | No Scheduler/Worker/provider authority model introduced; customer/tier/non-agent replay semantics unchanged |
| `QUA-001` exact evidence | Corrected implementation SHA `301a1ee3f3924038d992f0228457678c6c45d171`, CI `31317062806` / `#1372`, all 5 mandatory jobs success, full **436/2787**, focused **11/159**, retained artifact ID `9039083330`, independent digest verified | Exact corrected evidence-head CI remains a separate Worker gate until this docs head passes |

## Corrected application flow

For a new agent Quote, the bounded sequence remains:

1. validate Quote key/user/Offering/correlation and typed agent pricing context;
2. fail if actor user differs from Quote subject;
3. calculate request payload identity including agent action/authority mode;
4. if no exact existing Quote exists, revalidate active account and require agent context for an agent subject;
5. reject arbitrary caller-supplied agent override data;
6. read current agent profile code and call accepted `AgentPricingService` with deterministic key bound to Quote key, user, Offering and action;
7. if no rule matches, persist accepted agent-pricing resolution/profile provenance but keep Offering base price with `override_source=none`; if matched, take override amount/reference only from accepted resolution;
8. reject positive discount if accepted resolution snapshot forbids combination;
9. apply eligible discount after selected base/override price, using integer IRR only;
10. persist immutable Quote configuration plus exact agent-pricing identity/provenance;
11. MariaDB verifies inserted agent Quote binding against accepted `agent_pricing_resolutions` row.

For an exact existing Quote replay:

1. lock/read the Quote by key and validate exact request payload identity;
2. reconstruct/validate immutable stored Quote and its optional agent-pricing binding;
3. if persisted `agentPricing` binding is present, perform current actor/user/agent-profile/profile-code authorization checks, whether `override_source` is `agent` or `none`;
4. return the historical Quote only after those checks pass;
5. never invoke mutable agent-pricing resolver or recompute pricing for replay.

The duplicate-key `QueryException` fallback reconstructs the same Quote and invokes the same shared authorization guard, so it cannot bypass binding-based replay authorization.

## Storage binding

Forward migration remains `database/migrations/2026_08_09_003300_add_agent_pricing_binding_to_quotes.php`; this correction does not edit it or any applied migration.

The nullable columns preserve existing non-agent/legacy Quote rows while new agent Quote inserts are fail-closed. The binding records:

- accepted agent-pricing resolution ID/public ID/configuration hash;
- agent profile ID snapshot;
- pricing profile root ID/public ID/code/version/configuration hash;
- pricing action;
- matched rule root ID/public ID/code/version/configuration hash when present, null for no-match;
- `discount_combination_allowed`.

The Quote's existing `override_source`, override reference and override amount hold BUY-002 pricing components. A no-match agent-pricing Quote intentionally has `override_source=none` while retaining non-null agent-pricing binding. The existing MariaDB trigger cross-checks both matched and no-match shapes with accepted `agent_pricing_resolutions`. Existing Quote update/delete guards keep the complete row immutable after insertion.

## Corrected focused proof

Corrected implementation JUnit on CI `#1372`:

- `AgentPricingQuoteIntegrationTest` — **11 tests / 159 assertions**;
- `QuotePricingSnapshotTest` — **8 / 68**;
- accepted W-003 `AgentPricingResolutionFoundationTest` — **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest` — **7 / 74**;
- W-002 reservation/release compatibility — **18 / 80** across its four MariaDB/contention suites;
- full repository — **436 / 2787**.

Corrected no-match coverage proves:

- an authoritative no-match Purchase Quote persists an agent-pricing resolution/profile binding while storing `override_source=none`, null override reference/price, and base-price effective/final amounts;
- exact no-match replay after current user suspension is denied with no second Quote/resolution;
- exact no-match replay after current agent-profile suspension is denied with no second Quote/resolution;
- exact no-match replay after current profile-code mismatch is denied while the stored historical Quote remains unchanged;
- after pricing-profile and pricing-rule version-only revisions with the same active/current profile code, exact no-match replay succeeds without re-resolution and preserves original Quote price/hash plus resolution/profile/rule snapshot identity;
- `agent_pricing_resolutions` remains one row across accepted no-match replay.

Existing matched replay authorization, authoritative matched override, discount combination, arbitrary override denial, cross-user/non-agent/new-effect suspension/stale-profile denial, exact replay/conflict identity, direct DB forgery/missing-binding rejection and Quote immutability coverage remain green.

## Preserved compatibility

Customer, account and tier Quote behavior remains on accepted BUY-002 path with formula version `buy-002-v1`. Agent Quotes use `buy-002-agent-pricing-v1` and include accepted agent-pricing provenance whether matched or no-match.

The old BUY-002 regression that manually supplied an agent override remains converted to an authorization-denial assertion because such input is no longer authoritative after Issue #31. No accepted customer/tier Quote semantic is widened.

## Scope exclusions

No correction changes are made to:

- `AgentPricingService` profile/rule management or resolver selection behavior;
- `app/Modules/Promotions/**` or promotion migrations/lifecycle;
- applied Quote `002930`, Promotions `003000/003100`, or Agent Pricing `003200` migrations;
- the existing forward Quote binding migration `003300`;
- Payment Intent, providers, Wallet/ledger;
- Order aggregate/state machine;
- provisioning/Service lifecycle;
- workflows, runner/toolchain/project-control, provider-live/staging paths, PR #24 or `composer.lock`.

This record does not claim Phase 0.5 closure or independent acceptance. Exact corrected evidence-head CI/artifact verification plus MASTER correction review remain required, and PR #35 remains Draft for that review.
