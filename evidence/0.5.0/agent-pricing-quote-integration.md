# Phase 0.5 — AGT-005 Agent Pricing Quote Integration Evidence

**Worker:** W-004  
**Issue:** #31  
**Contract Revision:** 1  
**PR:** #35  
**Dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`  
**Previous reviewed HEAD:** `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6`  
**Corrected implementation SHA:** `301a1ee3f3924038d992f0228457678c6c45d171`  
**Status:** Worker correction evidence candidate; MASTER correction review/integration pending. PR #35 remains Draft.

## MASTER correction boundary

MASTER correction review of reviewed HEAD `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6` found one remaining authorization defect: `assertAgentQuoteReplayAuthorized()` decided whether current-state authorization was required from `override_source=agent`. That was too narrow because an authoritative agent-pricing no-match Quote intentionally persists `agent_pricing_resolution_id` / an `agentPricing` snapshot while falling back to Offering base price with `override_source=none`.

The bounded correction preserves immutable historical pricing while making the persisted agent-pricing binding the replay-authorization boundary:

- any replayed Quote carrying persisted agent-pricing provenance must pass the current agent replay authorization guard, whether the accepted resolution matched an override rule or produced no match/base-price fallback;
- the acting user must still be the Quote subject;
- the current `users` row must exist, remain active and have `account_type=agent`;
- the current `agent_profiles` row must exist and remain active;
- current `agent_profiles.pricing_profile_code` must exactly match the Quote's stored `agent_pricing_profile_code_snapshot`;
- missing, suspended, invalid or mismatched state fails closed;
- the replay path does **not** call `AgentPricingService::resolve()` and does not recalculate or reinterpret the stored base price, override, discount, rule/profile versions, configuration hashes, resolution identity or final amount;
- customer/tier/non-agent replay behavior remains unchanged because those Quotes do not carry an agent-pricing binding.

The same shared authorization guard is already used by both ordinary exact replay and the duplicate-key `QueryException` replay fallback, so the corrected binding-based detection applies to both paths. No schema or migration change was required.

## Bounded capability proven

This increment wires the already-accepted `AgentPricingService` resolver into immutable `BUY-002` Quote creation without changing accepted agent-pricing selection/management semantics.

For a new agent Quote:

- a typed Quote agent-pricing context binds the acting user and pricing action;
- cross-user or non-agent use fails closed before persistence;
- caller-supplied arbitrary agent override reference/amount is rejected;
- the current authoritative agent profile code is used only to invoke the accepted resolver;
- the deterministic resolver key is bound to Quote key, user, Offering and action;
- matched resolution applies `base -> agent override -> eligible discount -> final` using integer IRR only;
- no-match uses the accepted base-price fallback without inventing an override, while still persisting the accepted agent-pricing resolution/profile identity;
- positive discount is rejected when the accepted resolution snapshot has `discount_combination_allowed=false`;
- Quote persists the exact accepted agent-pricing resolution public identity/hash, agent profile, pricing profile identity/version/configuration hash, action, matched rule identity/version/configuration hash when present, override amount when matched and combination flag.

For exact replay of either a matched or no-match agent-pricing-bound Quote, the immutable request payload and stored Quote are validated, current agent replay authority is revalidated as above, and the stored Quote is returned without mutable pricing re-resolution. Later pricing-profile/rule version revisions with the same active profile code do not reinterpret the historical Quote.

The existing forward migration `2026_08_09_003300_add_agent_pricing_binding_to_quotes.php` remains unchanged. Existing applied Quote, Promotions and Agent Pricing migrations are unchanged. MariaDB checks/FKs/unique constraints plus the insert trigger continue to validate new agent Quote bindings against accepted `agent_pricing_resolutions`, including the no-match shape. Existing Quote update/delete immutability guards continue to protect the complete row after insert.

## Corrected implementation CI evidence

Exact corrected implementation HEAD `301a1ee3f3924038d992f0228457678c6c45d171` was tested through PR #35 merge candidate `1eac333327cfcfb816e433b821cf336e55356c25`.

CI run `31317062806` / `#1372` completed successfully with all five mandatory same-repository self-hosted jobs green:

1. Repository preflight — success;
2. Secret scan — success;
3. Dependency and license policy — success;
4. MariaDB and Redis tests — success;
5. PHP static quality — success, including Pint, PHPStan and repository architecture/policy checks.

MariaDB/Redis full suite: **436 tests / 2787 assertions**.

Focused/regression counts from retained corrected JUnit:

- `AgentPricingQuoteIntegrationTest`: **11 / 159**;
- `QuotePricingSnapshotTest`: **8 / 68**;
- accepted W-003 `AgentPricingResolutionFoundationTest`: **8 / 114**;
- W-001 `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- W-002 promotion reservation/release compatibility suites combined: **18 / 80**.

The W-002 total remains:

- `PromotionUsageReservationFoundationTest` — 10 / 44;
- `PromotionUsageReservationContentionVerificationTest` — 4 / 16;
- `PromotionUsageReservationCrossVersionCapacityTest` — 3 / 14;
- `PromotionUsageReservationCrossVersionContentionVerificationTest` — 1 / 6.

## Retained corrected artifact verification

Corrected implementation artifact:

- name: `test-evidence-31317062806`;
- artifact ID: `9039083330`;
- size: `127296` bytes;
- uploader digest: `sha256:7f97c02155e4d607116195d144a75a947cdf14d8b0bf1aeef46954bbde83a97f`;
- independently downloaded/recalculated SHA-256: `7f97c02155e4d607116195d144a75a947cdf14d8b0bf1aeef46954bbde83a97f`.

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
- authoritative no-match creates a non-null agent-pricing resolution/binding while intentionally storing `override_source=none`, null override reference/price and Offering base/effective/final price;
- no-match stores the accepted resolution identity and pricing-profile code/version/configuration hash while matched-rule identity remains null;
- discount-combination allowed/disallowed behavior remains deterministic;
- missing context, cross-user, non-agent, suspended agent, stale profile and caller-forged override denial remain covered for new Quotes;
- matched agent replay after current user or agent-profile suspension fails closed without a second Quote/resolution;
- matched agent replay after current profile-code mismatch fails closed while stored history remains unchanged;
- no-match/base-price agent replay after current user suspension fails closed without a second Quote or agent-pricing resolution;
- no-match/base-price agent replay after current agent-profile suspension fails closed without a second Quote or agent-pricing resolution;
- no-match/base-price agent replay after current profile-code mismatch fails closed while the stored historical Quote remains unchanged;
- no-match historical replay after pricing-profile and pricing-rule **version-only** revisions with the same active/current profile code succeeds without re-resolution and preserves original Quote base/effective/final prices, Quote configuration hash, resolution identity/hash, pricing-profile identity/version/hash and nullable matched-rule snapshot;
- changed same-key action/discount conflicts without re-resolution;
- MariaDB rejection of missing or forged agent-resolution binding;
- Quote update/delete immutability after the forward schema extension;
- no promotion reservation, Payment Intent or ledger effect from successful Quote creation.

The unchanged/reconciled BUY-002 Quote suite retains customer/account/tier behavior, base and override-before-discount arithmetic, replay/conflict, expiry, Offering-change historical stability and database immutability/hash guards. Its former manual agent-override regression remains intentionally replaced by denial because Issue #31 makes authoritative resolver consumption mandatory for agent Quotes.

## Superseded reviewed evidence

Reviewed HEAD `50d6de4e6e750c832ca8b29e86ed134ecb1a22e6` and CI `31312503636` / `#1355` were green but are **not** sufficient correction acceptance evidence: MASTER identified the no-match replay-authorization gap after that run and requested changes. The exact corrected implementation evidence above supersedes it for this correction lifecycle.

Earlier reviewed/diagnostic runs remain historical only and are not used as acceptance evidence for this correction.

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

This corrected implementation evidence, the bounded traceability record and bounded risk record are Worker correction evidence candidates. The exact evidence HEAD containing these updates must separately pass all five mandatory CI jobs and retained-artifact verification before W-004 can hand off corrections for review. MASTER correction review/integration remains mandatory, PR #35 must stay Draft, and W-004 must not merge.
