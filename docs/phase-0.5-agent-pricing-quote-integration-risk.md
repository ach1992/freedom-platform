# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Risk Record

**Issue:** #31  
**Worker:** W-004  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Implementation SHA:** `73b02ebc7457a068d0d8416fc69d395342bd88df`  
**Implementation CI:** `31298291510` / `#1331` — all five mandatory jobs successful  
**Status:** Worker evidence candidate; MASTER review/integration pending.

## Risk posture

| Risk | Severity | Worker control/evidence | Residual / next gate |
|---|---|---|---|
| Caller invents an agent override or mutable pricing is reinterpreted after Quote acceptance | High — financial/pricing integrity | New agent Quotes reject caller-supplied agent override data and consume only the accepted `AgentPricingService` resolution. Quote stores the exact resolution/profile/rule/version/configuration identity, action, override and combination flag. Exact Quote replay returns the stored Quote before mutable agent-pricing re-resolution. Historical-rule-revision test proves old Quote stability and new-Quote adoption of the later rule version. | Independent MASTER review and integration. Later purchase/payment execution must consume the immutable Quote rather than re-resolve pricing. |
| Cross-user/non-agent/suspended/stale agent context obtains agent pricing | High — authorization/security | Typed Quote agent-pricing context binds actor user to Quote subject before replay/persistence. Fresh effect revalidates active account; accepted resolver revalidates active agent profile/current pricing profile. Focused tests cover cross-user, non-agent, suspended and stale-profile denial. | Future HTTP/API/session wiring must preserve the same subject/actor boundary; this Worker does not add service/worker authority. |
| Forged or incomplete Quote-to-agent-resolution provenance is inserted directly | High — data integrity | Forward `003300` migration adds FKs, one-resolution-per-Quote uniqueness, shape/hash/action checks and a MariaDB insert trigger that cross-checks subject, Offering, action, profile, matched rule, override and combination policy against the accepted `agent_pricing_resolutions` row. Focused tests reject missing/forged bindings. Existing Quote update/delete guards keep the extended row immutable. | MASTER review of schema/trigger compatibility; future migrations must preserve the invariant. |
| Agent override/discount arithmetic ordering changes or monetary float enters pricing | High — pricing integrity | Existing BUY-002 integer-IRR path remains authoritative. Focused proof confirms Offering base -> accepted agent override -> eligible discount -> final. Positive discount fails when the accepted agent resolution snapshot forbids combination. Full and BUY-002 regression suites remain green. | Promotion discount selection/eligibility itself remains a separate accepted/later boundary; this Worker only consumes the discount input already owned by Quote. |
| AGT-005 integration accidentally mutates accepted AgentPricing selection/management semantics | High — compatibility | `AgentPricingService`, agent-pricing domain rules, and applied `003200` migration are read-only. Accepted `AgentPricingResolutionFoundationTest` remains green at **8 tests / 114 assertions**. | MASTER diff review must confirm no protected AgentPricing surface changed. |
| Quote integration interferes with W-001/W-002 promotion rule/reservation lifecycle | Medium — compatibility/contention | No `app/Modules/Promotions/**` or Promotions migration change. W-001 rule-resolution regression is **7 / 74**; W-002 reservation/release compatibility suites are **18 / 80**. Successful agent Quote proof records zero promotion reservations. | Promotion combination/reservation execution remains separate; no reservation/redemption/release effect is claimed here. |
| Quote creation accidentally creates payment, wallet, ledger, provider, Order, provisioning or Service effects | High — cross-phase safety | Focused test verifies no Payment Intent or ledger effect from successful Quote creation; implementation diff contains no payment/wallet/provider/Order-state/provisioning/Service code. | These effects remain later explicit boundaries and must not infer authority from this Worker candidate. |
| Evidence or CI artifact leaks credentials/sensitive data | High — security | Mandatory secret scan passed. Retained implementation artifact contains exactly five expected evidence files and an independent bounded scan found no authorization-header token, private-key block or obvious long credential assignment. | Repeat exact artifact/digest/scan on evidence HEAD. |
| Provider/live behavior is inferred from an offline pricing change | Low for this increment | No provider/live/staging code, workflow, Target or credential path is touched. | Provider/live acceptance remains governed by its own phase gates; this record makes no provider claim. |

## Implementation evidence anchor

Exact implementation HEAD `73b02ebc7457a068d0d8416fc69d395342bd88df` passed CI `31298291510` / `#1331` on PR #35's merge candidate `5bc60f0f7bb691cfeb0a635dc0ef36ef567c7c12`.

- full MariaDB/Redis suite: **431 tests / 2701 assertions**;
- focused `AgentPricingQuoteIntegrationTest`: **6 / 73**;
- BUY-002 `QuotePricingSnapshotTest`: **8 / 68**;
- accepted AgentPricing resolver regression: **8 / 114**;
- W-001 Promotions rule-resolution regression: **7 / 74**;
- W-002 reservation/release compatibility regressions: **18 / 80**.

Retained artifact `test-evidence-31298291510`, ID `9033753890`, size `126890` bytes. Uploader and independent SHA-256 are identical:

`dceff64acc55b4b3a496ce286da2c718edeee55b51c4da5e94c13aa88b8a9a11`

The implementation evidence and traceability records are:

- `evidence/0.5.0/agent-pricing-quote-integration.md`;
- `docs/phase-0.5-agent-pricing-quote-integration-traceability.md`.

## Explicit residual boundaries / non-claims

This Worker candidate does not implement or accept:

- `AGT-003` or `AGT-004` agent purchase/wallet behavior;
- Promotions rule selection changes, promotion reservation/redemption/release, or promotion schema changes;
- Payment Intent, payment provider, Wallet or ledger effects;
- paid Order state-machine transitions;
- provisioning or Service lifecycle;
- provider/live/staging behavior;
- Phase 0.5 closure, Phase 0.6 behavior or release acceptance.

The agent-pricing resolver is an accepted read-only dependency. This Worker does not weaken its most-specific selection, ambiguity, management authorization, immutable history or stored-resolution semantics.

## Remaining Worker gate

This risk record does not convert the candidate to accepted status. The exact evidence HEAD containing this record, the evidence record and traceability record must pass all five mandatory CI jobs again, with retained artifact metadata and an independently verified digest. The final PR diff, target branch, Contract Revision and non-claims must then be re-verified before Worker handoff. Independent MASTER review/owner approval remains mandatory; W-004 must not merge PR #35.
