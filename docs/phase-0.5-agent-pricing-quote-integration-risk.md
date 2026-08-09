# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Risk Record

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Previous reviewed HEAD:** `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6`  
**Corrected implementation SHA:** `301a1ee3f3924038d992f0228457678c6c45d171`  
**Corrected implementation CI:** `31317062806` / `#1372` — all five mandatory jobs successful  
**Status:** Worker correction evidence candidate; MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction risk

MASTER correction review of reviewed HEAD `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6` identified one remaining High authorization risk: replay-current-state authorization was gated by `override_source=agent`, but an authoritative agent-pricing no-match Quote legitimately persists an agent-pricing resolution/binding while using `override_source=none` and base-price fallback. Such a Quote could therefore replay after suspension/profile-code drift without current-state reauthorization.

The correction keys replay authorization from persisted `agentPricing` binding instead of override source. Both matched override and no-match/base fallback agent Quotes now require actor/subject identity, current active agent account, current active agent profile and exact current profile-code equality with stored snapshot before replay return. Customer/tier/non-agent Quotes carry no agent-pricing binding and retain existing replay behavior. The same shared guard covers ordinary replay and duplicate-key fallback.

## Risk posture

| Risk | Severity | Corrected Worker control/evidence | Residual / next gate |
|---|---|---|---|
| Suspended/stale/invalid agent replays an accepted agent-pricing-bound Quote, including no-match/base fallback | High — authorization/security | Before any existing Quote with persisted agent-pricing binding is returned, replay requires actor=stored subject, current active `users.account_type=agent`, current active `agent_profiles`, and current `pricing_profile_code` exactly matching stored snapshot. Focused tests deny user suspension, profile suspension and profile-code mismatch for no-match Quotes while proving no second Quote/resolution and unchanged history. Existing matched replay denial tests remain green. | MASTER correction review must verify binding-based replay authorization. Future API/session wiring must preserve actor identity. |
| No-match agent Quote is mistaken for ordinary non-agent/base-price Quote because `override_source=none` | High — authorization/data semantics | Replay classification uses persisted `agentPricing` provenance, not `override_source`. Creation test proves no-match stores `override_source=none` and base price **with** non-null agent-pricing resolution/profile binding and nullable rule snapshot. | Future Quote readers must preserve the distinction between pricing outcome (`override_source`) and pricing authority/provenance (`agentPricing`). |
| Replay authorization accidentally re-prices or mutates historical financial terms | High — financial/pricing integrity | Replay validates current eligibility only; it does not call `AgentPricingService::resolve()`, does not recalculate base/override/discount/final amount, and does not rewrite profile/rule versions or configuration hashes. No-match version-only revision test proves replay retains original Quote hash/prices and resolution/profile/rule snapshots with exactly one resolution row. Existing matched history proof remains green. | Later purchase/payment execution must consume immutable Quote rather than re-resolve pricing. |
| Caller invents an agent override on new Quote creation | High — financial/pricing integrity | New agent Quotes reject caller-supplied agent override data and consume only accepted `AgentPricingService` resolution. Quote stores exact resolution/profile/rule/version/configuration identity, action, override when matched and combination flag. | Independent MASTER review and integration. |
| Cross-user/non-agent/suspended/stale agent context obtains fresh agent pricing | High — authorization/security | Typed Quote agent-pricing context binds actor user to Quote subject; fresh effects revalidate active account and accepted resolver revalidates active agent profile/current pricing profile. Existing focused tests cover cross-user, non-agent, suspended and stale-profile denial. | Future HTTP/API/session wiring must preserve same subject/actor boundary; this Worker does not add service/worker authority. |
| Forged or incomplete Quote-to-agent-resolution provenance is inserted directly | High — data integrity | Existing forward `003300` migration adds FKs, one-resolution-per-Quote uniqueness, shape/hash/action checks and MariaDB insert trigger cross-checking subject, Offering, action, profile, nullable matched rule, override and combination policy against accepted `agent_pricing_resolutions`. Existing tests reject missing/forged bindings; correction does not alter schema. | MASTER review of existing schema/trigger compatibility. |
| Agent override/discount arithmetic ordering changes or monetary float enters pricing | High — pricing integrity | Existing BUY-002 integer-IRR path remains authoritative. Matched proof confirms Offering base -> accepted agent override -> eligible discount -> final; no-match proof confirms Offering base fallback without invented override. BUY-002 regressions remain green. | Promotion discount selection/eligibility remains separate accepted/later boundary. |
| AGT-005 integration accidentally mutates accepted AgentPricing selection/management semantics | High — compatibility | `AgentPricingService`, agent-pricing domain rules, and applied `003200` migration remain read-only. Accepted W-003 `AgentPricingResolutionFoundationTest` remains green at **8 / 114**. | MASTER diff review must confirm no protected AgentPricing surface changed. |
| Quote integration interferes with W-001/W-002 promotion rule/reservation lifecycle | Medium — compatibility/contention | No `app/Modules/Promotions/**` or Promotions migration change. W-001 rule-resolution regression is **7 / 74**; W-002 reservation/release compatibility suites are **18 / 80**. | Promotion combination/reservation execution remains separate; no reservation/redemption/release effect is claimed here. |
| Quote creation/replay accidentally creates payment, wallet, ledger, provider, Order, provisioning or Service effects | High — cross-phase safety | Correction stays inside Quote application/test/evidence surfaces. Existing proof records no Payment Intent or ledger effect from Quote creation, and replay denial/success creates no second Quote/resolution. | These effects remain later explicit boundaries and must not infer authority from this Worker correction. |
| Evidence or CI artifact leaks credentials/sensitive data | High — security | Mandatory corrected implementation secret scan passed. Retained artifact contains exactly five expected evidence files; independent SHA-256 matches uploader and bounded scan found zero authorization-header/private-key/obvious long credential-pattern matches. | Repeat exact artifact/digest/scan on corrected evidence HEAD. |
| Provider/live behavior is inferred from an offline pricing change | Low for this increment | No provider/live/staging code, workflow, Target or credential path is touched. | Provider/live acceptance remains governed by its own phase gates; this record makes no provider claim. |

## Corrected implementation evidence anchor

Exact corrected implementation HEAD `301a1ee3f3924038d992f0228457678c6c45d171` passed CI `31317062806` / `#1372` on PR #35 merge candidate `1eac333327cfcfb816e433b821cf336e55356c25`.

- full MariaDB/Redis suite: **436 tests / 2787 assertions**;
- focused `AgentPricingQuoteIntegrationTest`: **11 / 159**;
- BUY-002 `QuotePricingSnapshotTest`: **8 / 68**;
- accepted W-003 AgentPricing resolver regression: **8 / 114**;
- W-001 Promotions rule-resolution regression: **7 / 74**;
- W-002 reservation/release compatibility regressions: **18 / 80**.

Retained corrected implementation artifact `test-evidence-31317062806`, ID `9039083330`, size `127296` bytes. Uploader and independent SHA-256 are identical:

`7f97c02155e4d607116195d144a75a947cdf14d8b0bf1aeef46954bbde83a97f`

The corrected implementation evidence and traceability records are:

- `evidence/0.5.0/agent-pricing-quote-integration.md`;
- `docs/phase-0.5-agent-pricing-quote-integration-traceability.md`.

Reviewed HEAD `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6` and its green CI are superseded for this correction lifecycle because MASTER requested changes for the no-match replay-authorization defect.

## Explicit residual boundaries / non-claims

This Worker correction does not implement or accept:

- `AGT-003` or `AGT-004` agent purchase/wallet behavior;
- Promotions rule selection changes, promotion reservation/redemption/release, or promotion schema changes;
- Payment Intent, payment provider, Wallet or ledger effects;
- paid Order state-machine transitions;
- provisioning or Service lifecycle;
- provider/live/staging behavior;
- Phase 0.5 closure, Phase 0.6 behavior or release acceptance.

The agent-pricing resolver is an accepted read-only dependency. This Worker correction does not weaken or alter its most-specific selection, ambiguity, management authorization, immutable history or stored-resolution semantics.

## Remaining Worker correction gate

This risk record does not convert the candidate to accepted status. The exact corrected evidence HEAD containing this record, the corrected evidence record and traceability record must pass all five mandatory CI jobs again, with retained artifact metadata and independently verified digest. The final PR diff, Draft state, target branch, Contract Revision and non-claims must then be re-verified before Worker correction handoff. Independent MASTER correction review/owner approval remains mandatory; W-004 must not merge PR #35.
