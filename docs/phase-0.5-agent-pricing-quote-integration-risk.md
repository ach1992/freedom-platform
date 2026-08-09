# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Risk Record

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Corrected implementation SHA:** `7878bd213c62c3e8d436b2eaeb74d223061b3fb2`  
**Corrected implementation CI:** `31312232236` / `#1348` — all five mandatory jobs successful  
**Status:** Worker correction evidence candidate; MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction risk

MASTER review of reviewed HEAD `ccdc00e8207bbb389faac4d61e67331943517dfc` identified one High authorization risk: exact agent-priced Quote replay could return the historical Quote before current user/agent-profile/profile-code eligibility was revalidated. The correction adds current-state authorization before return while deliberately leaving historical pricing immutable and avoiding `AgentPricingService::resolve()` on replay.

The replay guard applies only to existing Quotes with `override_source=agent`; accepted customer/tier/non-agent replay semantics remain unchanged. It verifies actor/subject identity, current active agent account, current active agent profile, and exact current profile-code equality with the stored Quote snapshot. The same guard also protects the duplicate-key race replay fallback.

## Risk posture

| Risk | Severity | Corrected Worker control/evidence | Residual / next gate |
|---|---|---|---|
| Suspended/stale/invalid agent replays an already accepted agent-priced Quote | High — authorization/security | Before an existing `override_source=agent` Quote is returned, replay now requires actor=stored subject, current active `users.account_type=agent`, current active `agent_profiles`, and current `pricing_profile_code` exactly matching the stored snapshot. Focused tests deny suspended user, suspended profile and profile-code mismatch while proving no new Quote or agent-pricing resolution is created. | MASTER correction review must verify the replay authorization boundary. Future API/session wiring must preserve actor identity. |
| Replay authorization accidentally re-prices or mutates historical financial terms | High — financial/pricing integrity | Replay validates current eligibility only; it does not call `AgentPricingService::resolve()`, does not recalculate override/discount/final amount, and does not rewrite profile/rule versions or configuration hashes. A focused test revises both profile and rule versions while keeping the same active profile code and proves replay returns the original historical resolution/version/pricing, while a later new Quote consumes later versions. | Later purchase/payment execution must consume the immutable Quote rather than re-resolve pricing. |
| Caller invents an agent override on new Quote creation | High — financial/pricing integrity | New agent Quotes reject caller-supplied agent override data and consume only the accepted `AgentPricingService` resolution. Quote stores exact resolution/profile/rule/version/configuration identity, action, override and combination flag. | Independent MASTER review and integration. |
| Cross-user/non-agent/suspended/stale agent context obtains fresh agent pricing | High — authorization/security | Typed Quote agent-pricing context binds actor user to Quote subject; fresh effects revalidate active account and the accepted resolver revalidates active agent profile/current pricing profile. Existing focused tests cover cross-user, non-agent, suspended and stale-profile denial. | Future HTTP/API/session wiring must preserve the same subject/actor boundary; this Worker does not add service/worker authority. |
| Forged or incomplete Quote-to-agent-resolution provenance is inserted directly | High — data integrity | Existing forward `003300` migration adds FKs, one-resolution-per-Quote uniqueness, shape/hash/action checks and a MariaDB insert trigger that cross-checks subject, Offering, action, profile, matched rule, override and combination policy against the accepted `agent_pricing_resolutions` row. Existing focused tests reject missing/forged bindings. Existing Quote update/delete guards keep the extended row immutable. | MASTER review of schema/trigger compatibility; correction does not modify schema or migration. |
| Agent override/discount arithmetic ordering changes or monetary float enters pricing | High — pricing integrity | Existing BUY-002 integer-IRR path remains authoritative. Focused proof confirms Offering base -> accepted agent override -> eligible discount -> final. Positive discount fails when the accepted agent resolution snapshot forbids combination. Full and BUY-002 regression suites remain green. | Promotion discount selection/eligibility itself remains a separate accepted/later boundary; this Worker only consumes the discount input already owned by Quote. |
| AGT-005 integration accidentally mutates accepted AgentPricing selection/management semantics | High — compatibility | `AgentPricingService`, agent-pricing domain rules, and applied `003200` migration remain read-only. Accepted W-003 `AgentPricingResolutionFoundationTest` remains green at **8 tests / 114 assertions**. | MASTER diff review must confirm no protected AgentPricing surface changed. |
| Quote integration interferes with W-001/W-002 promotion rule/reservation lifecycle | Medium — compatibility/contention | No `app/Modules/Promotions/**` or Promotions migration change. W-001 rule-resolution regression is **7 / 74**; W-002 reservation/release compatibility suites are **18 / 80**. | Promotion combination/reservation execution remains separate; no reservation/redemption/release effect is claimed here. |
| Quote creation/replay accidentally creates payment, wallet, ledger, provider, Order, provisioning or Service effects | High — cross-phase safety | Implementation and correction stay inside Quote application/test/evidence surfaces. Existing focused proof records no Payment Intent or ledger effect from successful Quote creation, and replay denial creates no new Quote/resolution. | These effects remain later explicit boundaries and must not infer authority from this Worker correction. |
| Evidence or CI artifact leaks credentials/sensitive data | High — security | Mandatory corrected implementation secret scan passed. Retained corrected artifact contains exactly five expected evidence files and an independent bounded scan found no authorization-header token, private-key block or obvious long password/secret/token/API-key assignment. | Repeat exact artifact/digest/scan on corrected evidence HEAD. |
| Provider/live behavior is inferred from an offline pricing change | Low for this increment | No provider/live/staging code, workflow, Target or credential path is touched. | Provider/live acceptance remains governed by its own phase gates; this record makes no provider claim. |

## Corrected implementation evidence anchor

Exact corrected implementation HEAD `7878bd213c62c3e8d436b2eaeb74d223061b3fb2` passed CI `31312232236` / `#1348` on PR #35 merge candidate `cd8eb434b6eaf3851f8170d1d785b2bd03343ef6`.

- full MariaDB/Redis suite: **433 tests / 2718 assertions**;
- focused `AgentPricingQuoteIntegrationTest`: **8 / 90**;
- BUY-002 `QuotePricingSnapshotTest`: **8 / 68**;
- accepted W-003 AgentPricing resolver regression: **8 / 114**;
- W-001 Promotions rule-resolution regression: **7 / 74**;
- W-002 reservation/release compatibility regressions: **18 / 80**.

Retained corrected implementation artifact `test-evidence-31312232236`, ID `9037731851`, size `127200` bytes. Uploader and independent SHA-256 are identical:

`ea1bf76c9abd28cfb845f81727344f6b73d92dabb78b83646321743540fd36b9`

The corrected implementation evidence and traceability records are:

- `evidence/0.5.0/agent-pricing-quote-integration.md`;
- `docs/phase-0.5-agent-pricing-quote-integration-traceability.md`.

Reviewed HEAD `ccdc00e8207bbb389faac4d61e67331943517dfc` and its green CI are superseded for the correction lifecycle because MASTER requested changes for the replay-authorization defect.

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

This risk record does not convert the candidate to accepted status. The exact corrected evidence HEAD containing this record, the corrected evidence record and traceability record must pass all five mandatory CI jobs again, with retained artifact metadata and an independently verified digest. The final PR diff, Draft state, target branch, Contract Revision and non-claims must then be re-verified before Worker correction handoff. Independent MASTER correction review/owner approval remains mandatory; W-004 must not merge PR #35.
