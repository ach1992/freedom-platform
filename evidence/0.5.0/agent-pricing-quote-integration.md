# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Evidence

**Worker:** W-004  
**Issue:** #31  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Corrected implementation SHA:** `7878bd213c62c3e8d436b2eaeb74d223061b3fb2`  
**Status:** Worker correction evidence candidate; MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction boundary

MASTER review of reviewed HEAD `ccdc00e8207bbb389faac4d61e67331943517dfc` found one required authorization defect: an exact agent-priced Quote replay could return the immutable stored Quote before revalidating the current user/agent-profile authority. Contract Revision 1 already required suspended, stale-profile, invalid-agent and cross-user attempts to fail closed before replay effect, so no Contract Revision or protected-surface expansion was required.

The bounded correction preserves immutable historical pricing while adding current-state authorization before an existing Quote with `override_source=agent` is returned:

- the acting user must still be the Quote subject;
- the current `users` row must exist, remain active and have `account_type=agent`;
- the current `agent_profiles` row must exist and remain active;
- current `agent_profiles.pricing_profile_code` must exactly match the Quote's stored `agent_pricing_profile_code_snapshot`;
- missing, suspended, invalid or mismatched state fails closed;
- the replay path does **not** call `AgentPricingService::resolve()` and does not recalculate or reinterpret the stored override, discount, rule, pricing-profile version, configuration hashes or final amount;
- customer/tier/non-agent replay behavior remains unchanged.

The same authorization guard is applied to both the ordinary exact-replay path and the duplicate-key race fallback path. No schema or migration change was required for the correction.

## Bounded capability proven

This increment wires the already-accepted `AgentPricingService` resolver into immutable `BUY-002` Quote creation without changing accepted agent-pricing selection/management semantics.

For a new agent Quote:

- a typed Quote agent-pricing context binds the acting user and pricing action;
- cross-user or non-agent use fails closed before persistence;
- caller-supplied arbitrary agent override reference/amount is rejected;
- the current authoritative agent profile code is used only to invoke the accepted resolver;
- the deterministic resolver key is bound to Quote key, user, Offering and action;
- matched resolution applies `base -> agent override -> eligible discount -> final` using integer IRR only;
- no-match uses the accepted base-price fallback without inventing an override;
- positive discount is rejected when the accepted resolution snapshot has `discount_combination_allowed=false`;
- Quote persists the exact accepted agent-pricing resolution public identity/hash, agent profile, pricing profile identity/version/configuration hash, action, matched rule identity/version/configuration hash, override amount and combination flag.

For an exact accepted agent-priced Quote replay, the immutable request payload and stored Quote are validated, current agent replay authority is revalidated as above, and the stored Quote is returned without mutable pricing re-resolution. Later pricing-profile/rule version revisions with the same active profile code affect later Quotes only, not the historical accepted Quote.

A forward-only migration `2026_08_09_003300_add_agent_pricing_binding_to_quotes.php` extends Quote storage. Existing applied Quote, Promotions and Agent Pricing migrations are unchanged. MariaDB checks/FKs/unique constraints plus an insert trigger validate that a new agent Quote's stored binding exactly matches the accepted `agent_pricing_resolutions` row. Existing Quote update/delete immutability guards continue to protect the complete row after insert.

## Corrected implementation CI evidence

Exact corrected implementation HEAD `7878bd213c62c3e8d436b2eaeb74d223061b3fb2` was tested through PR #35's merge candidate `cd8eb434b6eaf3851f8170d1d785b2bd03343ef6`.

CI run `31312232236` / `#1348` completed successfully with all five mandatory same-repository self-hosted jobs green:

1. Repository preflight — success;
2. Secret scan — success;
3. Dependency and license policy — success;
4. MariaDB and Redis tests — success;
5. PHP static quality — success, including Pint, PHPStan and repository architecture/policy checks.

MariaDB/Redis full suite: **433 tests / 2718 assertions**.

Focused/regression counts from the retained corrected JUnit:

- `AgentPricingQuoteIntegrationTest`: **8 / 90**;
- `QuotePricingSnapshotTest`: **8 / 68**;
- accepted W-003 `AgentPricingResolutionFoundationTest`: **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- W-002 promotion reservation/release compatibility suites combined: **18 / 80**.

The W-002 total is the sum of:

- `PromotionUsageReservationFoundationTest` — 10 / 44;
- `PromotionUsageReservationContentionVerificationTest` — 4 / 16;
- `PromotionUsageReservationCrossVersionCapacityTest` — 3 / 14;
- `PromotionUsageReservationCrossVersionContentionVerificationTest` — 1 / 6.

## Retained corrected artifact verification

Corrected implementation artifact:

- name: `test-evidence-31312232236`;
- artifact ID: `9037731851`;
- size: `127200` bytes;
- uploader digest: `sha256:ea1bf76c9abd28cfb845f81727344f6b73d92dabb78b83646321743540fd36b9`;
- independently downloaded/recalculated SHA-256: `ea1bf76c9abd28cfb845f81727344f6b73d92dabb78b83646321743540fd36b9`.

The retained ZIP contains exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

A bounded scan of the downloaded artifact found no authorization-header token, private-key block, or obvious long password/secret/token/API-key assignment match.

## Required behavior exercised

The corrected focused suite proves:

- matched authoritative agent override and exact identity snapshots;
- explicit no-match base fallback;
- discount-combination allowed/disallowed behavior;
- missing context, cross-user, non-agent, suspended agent, stale profile and caller-forged override denial for new Quotes;
- exact agent-priced replay after current user suspension is denied without creating another Quote or agent-pricing resolution;
- exact agent-priced replay after current agent-profile suspension is denied without creating another Quote or agent-pricing resolution;
- exact agent-priced replay after current `pricing_profile_code` mismatch is denied while the stored historical Quote remains byte-for-byte unchanged at the row level;
- exact replay after both pricing-profile and pricing-rule **version** revision, while the same profile code remains active/current, returns the historical accepted Quote with its original pricing-profile/rule versions and resolution identity without re-pricing;
- changed same-key action/discount conflicts without re-resolution;
- a new later Quote consumes the later pricing-profile/rule versions;
- MariaDB rejection of missing or forged agent-resolution binding;
- Quote update/delete immutability after the forward schema extension;
- no promotion reservation, Payment Intent or ledger effect from successful Quote creation.

The unchanged/reconciled BUY-002 Quote suite retains customer/account/tier behavior, base and override-before-discount arithmetic, replay/conflict, expiry, Offering-change historical stability and database immutability/hash guards. Its former manual agent-override regression remains intentionally replaced by denial because Issue #31 makes authoritative resolver consumption mandatory for agent Quotes.

## Superseded reviewed evidence

Reviewed HEAD `ccdc00e8207bbb389faac4d61e67331943517dfc` and CI `31298749181` / `#1335` were green but are **not** correction acceptance evidence: MASTER correctly identified the replay-authorization semantic defect after that run and requested changes. The corrected implementation evidence above supersedes that reviewed-head evidence for the correction lifecycle.

Earlier diagnostic CI `31297574315` / `#1320` and `31297817643` / `#1328` also remain diagnostic only and are not used as correction acceptance evidence.

## Explicit non-claims

This Worker correction does **not** claim or implement:

- `AGT-003` / `AGT-004` agent purchase workflows or agent wallet purchasing;
- promotion rule/reservation schema or lifecycle changes;
- Payment Intent, payment provider, wallet or ledger effects;
- paid Order state-machine behavior;
- provisioning or Service lifecycle behavior;
- provider/live/staging execution;
- Phase 0.5 closure, Phase 0.6 behavior or release acceptance.

`AgentPricingService` management, most-specific selection, ambiguity handling and stored resolution semantics remain read-only accepted dependencies.

## Evidence-head lifecycle

This corrected implementation evidence, the bounded traceability record and bounded risk record are Worker correction evidence candidates. The exact evidence HEAD containing their correction updates must separately pass all five mandatory CI jobs and retained-artifact verification before W-004 can hand off corrections for review. MASTER correction review/integration remains mandatory, PR #35 must stay Draft for correction review, and W-004 must not merge.
