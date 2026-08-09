# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Evidence

**Worker:** W-004  
**Issue:** #31  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Implementation SHA:** `73b02ebc7457a068d0d8416fc69d395342bd88df`  
**Status:** Worker evidence candidate; MASTER review/integration pending.

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
- Quote persists the exact accepted agent-pricing resolution public identity/hash, agent profile, pricing profile identity/version/configuration hash, action, matched rule identity/version/configuration hash, override amount and combination flag;
- exact Quote replay validates the immutable request payload and returns the stored Quote before any mutable agent-pricing re-resolution;
- later pricing rule revision changes only later Quotes, not an accepted historical Quote.

A forward-only migration `2026_08_09_003300_add_agent_pricing_binding_to_quotes.php` extends Quote storage. Existing applied Quote, Promotions and Agent Pricing migrations are unchanged. MariaDB checks/FKs/unique constraints plus an insert trigger validate that a new agent Quote's stored binding exactly matches the accepted `agent_pricing_resolutions` row. Existing Quote update/delete immutability guards continue to protect the complete row after insert.

## Implementation CI evidence

Exact implementation HEAD `73b02ebc7457a068d0d8416fc69d395342bd88df` was tested through PR #35's current merge candidate `5bc60f0f7bb691cfeb0a635dc0ef36ef567c7c12`.

CI run `31298291510` / `#1331` completed successfully with all five mandatory same-repository self-hosted jobs green:

1. Repository preflight — success;
2. Secret scan — success;
3. Dependency and license policy — success;
4. MariaDB and Redis tests — success;
5. PHP static quality — success, including Pint, PHPStan and repository architecture/policy checks.

MariaDB/Redis full suite: **431 tests / 2701 assertions**.

Focused/regression counts from retained JUnit:

- `AgentPricingQuoteIntegrationTest`: **6 / 73**;
- `QuotePricingSnapshotTest`: **8 / 68**;
- accepted `AgentPricingResolutionFoundationTest`: **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- W-002 promotion reservation/release compatibility suites combined: **18 / 80**.

The W-002 total is the sum of:

- `PromotionUsageReservationFoundationTest` — 10 / 44;
- `PromotionUsageReservationContentionVerificationTest` — 4 / 16;
- `PromotionUsageReservationCrossVersionCapacityTest` — 3 / 14;
- `PromotionUsageReservationCrossVersionContentionVerificationTest` — 1 / 6.

## Retained artifact verification

Implementation artifact:

- name: `test-evidence-31298291510`;
- artifact ID: `9033753890`;
- size: `126890` bytes;
- uploader digest: `sha256:dceff64acc55b4b3a496ce286da2c718edeee55b51c4da5e94c13aa88b8a9a11`;
- independently downloaded/recalculated SHA-256: `dceff64acc55b4b3a496ce286da2c718edeee55b51c4da5e94c13aa88b8a9a11`.

The retained ZIP contains exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

A bounded scan of the downloaded artifact found no authorization-header token, private-key block, or obvious long password/secret/token/API-key assignment match.

## Required behavior exercised

The focused suite proves:

- matched authoritative agent override and exact identity snapshots;
- explicit no-match base fallback;
- discount-combination allowed/disallowed behavior;
- missing context, cross-user, non-agent, suspended agent, stale profile and caller-forged override denial;
- exact replay without creating a second `agent_pricing_resolutions` row;
- changed same-key action/discount conflict without re-resolution;
- historical Quote stability after later agent-pricing rule revision, with a new Quote consuming the later version;
- MariaDB rejection of missing or forged agent-resolution binding;
- Quote update/delete immutability after the forward schema extension;
- no promotion reservation, Payment Intent or ledger effect from successful Quote creation.

The unchanged/reconciled BUY-002 Quote suite retains customer/account/tier behavior, base and override-before-discount arithmetic, replay/conflict, expiry, Offering-change historical stability and database immutability/hash guards. Its former manual agent-override regression is intentionally replaced by denial because Issue #31 makes authoritative resolver consumption mandatory for agent Quotes.

## Diagnostic runs not used as evidence

- CI `31297574315` / `#1320` was diagnostic only: the new tests attempted to insert a second singleton owner already seeded by `DatabaseSeeder`, and Pint identified formatting-only brace placement. The fixture was corrected to reuse the seeded owner and formatting was fixed.
- CI `31297817643` / `#1328` was diagnostic only: MariaDB/full behavior was green (`431 / 2701`) and Pint was green, while PHPStan required the existing `QuoteRow` shape alias to be attached to the agent-pricing row reader. That annotation-only correction produced the final implementation SHA above.

Neither diagnostic run is used as acceptance evidence.

## Explicit non-claims

This Worker candidate does **not** claim or implement:

- `AGT-003` / `AGT-004` agent purchase workflows or agent wallet purchasing;
- promotion rule/reservation schema or lifecycle changes;
- Payment Intent, payment provider, wallet or ledger effects;
- paid Order state-machine behavior;
- provisioning or Service lifecycle behavior;
- provider/live/staging execution;
- Phase 0.5 closure, Phase 0.6 behavior or release acceptance.

`AgentPricingService` management, most-specific selection, ambiguity handling and stored resolution semantics remain read-only accepted dependencies.

## Evidence-head lifecycle

This document, the bounded traceability record and bounded risk record are Worker evidence candidates. The exact evidence HEAD must separately pass all five mandatory CI jobs and retained-artifact verification before W-004 can hand off `READY_FOR_REVIEW`. MASTER review/integration remains mandatory and the Worker must not merge PR #35.
