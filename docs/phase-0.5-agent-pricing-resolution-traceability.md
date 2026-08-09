# Phase 0.5 agent pricing resolution traceability

Issue `#28`, Contract Revision `1`, Worker `W-003`.

| Contract behavior | Implementation | Verification |
|---|---|---|
| Stored/versioned pricing profiles | `AgentPricingProfileDefinition`, `AgentPricingManagementSupport`, `agent_pricing_profiles`, `agent_pricing_profile_versions` | focused management/replay tests + MariaDB guards |
| Stored/versioned pricing rules | `AgentPricingRuleDefinition`, `AgentPricingManagementSupport`, `agent_pricing_rules`, `agent_pricing_rule_versions` | focused rule/version tests + MariaDB guards |
| Integer IRR override | `AgentPricingRuleDefinition::$overridePriceIrr`, DB checks, resolution receipt/snapshot | focused supported-scope and negative-value tests |
| Most-specific action / offering / server / product rule | `AgentPricingPersistenceSupport::selectRule()` | individual scopes, compound specificity, row-order independence |
| Equal-specificity ambiguity fails closed | `selectRule()` unique top-winner requirement | ambiguity test asserts exception and no persisted resolution |
| Explicit no-match | nullable rule/override resolution shape | no-match test asserts immutable resolution with null rule/override |
| Current active agent/profile boundary | `AgentPricingService::resolve()` and insert guards | cross-user, customer, suspended, limited, missing-profile, stale-profile tests |
| Snapshotted discount-combination policy | profile version plus accepted resolution snapshot | allow/disallow policy tests; no Promotions resolution side effect |
| Immutable accepted identity/configuration | append-only version tables, resolution snapshots/hashes, triggers | DB mutation/delete/hash rejection tests |
| Exact replay / changed-request conflict | `resolution_key` + request payload hash | replay stability after later config changes; conflict test |
| No financial/downstream side effects | resolver returns foundation result only | ledger/payment/Promotion resolution counts remain zero |
| Admin-only pricing configuration | `agents.pricing.manage` via administrator authorizer | allowed sales-content and denied support test |

## Regression evidence

Implementation CI run `#1293` / ID `31289482939` passed the full `407 tests / 2543 assertions` suite. JUnit confirms `AgentPricingResolutionFoundationTest` `8/114`, BUY-002 `QuotePricingSnapshotTest` `8/63`, and accepted #25 `PromotionRuleResolutionFoundationTest` `7/74`.

## Boundary

Quote does not consume this resolver in Issue #28. Promotions is read-only from this worker. The foundation exposes the snapshotted result and policy needed for a later bounded Quote integration without altering accepted BUY-002 or Promotions semantics.