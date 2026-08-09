# AGT-005 agent pricing resolution foundation evidence

- Worker: `W-003`
- Issue: `#28`
- Contract revision: `1`
- Parent issue: `#8`
- Target branch: `develop/v1.0.0-completion`
- Accepted BASE_SHA: `8a501c149614533d93bec3d69a19cc3e584db2d7`
- Implementation SHA: `552fa7e30f3f575e1697f176d07fe66d0372e2ee`
- Implementation CI: run `#1293`, ID `31289482939`

## Mandatory CI

All five mandatory jobs passed for the exact implementation SHA:

1. Repository preflight — success
2. Secret scan — success
3. Dependency and license policy — success
4. PHP static quality — success
5. MariaDB and Redis tests — success

The complete automated suite reported `407 tests, 2543 assertions`.

Focused JUnit counts from the retained test artifact:

- `Tests\Feature\AgentPricingResolutionFoundationTest`: `8 tests, 114 assertions`
- unchanged BUY-002 `Tests\Feature\QuotePricingSnapshotTest`: `8 tests, 63 assertions`
- accepted #25 Promotions `Tests\Feature\PromotionRuleResolutionFoundationTest`: `7 tests, 74 assertions`

## Retained test artifact

- Artifact: `test-evidence-31289482939`
- Artifact ID: `9030946151`
- GitHub retention expiry: `2026-09-08T02:04:48Z`
- Independently calculated downloaded ZIP SHA-256: `ab4a327794550eb8e1cdc85b0d504a323616d8f1e5c166462d07246ef2076eb3`

The independent digest was calculated from the downloaded artifact ZIP, not copied from upload metadata.

## Base and scope verification

Comparison of BASE_SHA to the implementation SHA is `ahead`, with `ahead_by=10`, `behind_by=0`, and merge-base exactly equal to BASE_SHA. The implementation changes 23 files, limited to the Agents module, the new agent-pricing migration and access seeder, `DatabaseSeeder`, and focused feature tests. No `app/Modules/Promotions/**`, Promotions migration, Quote semantics, CI workflow/toolchain, or `composer.lock` file is modified.

## Delivered boundary

This foundation stores and versions agent pricing profiles and rules, resolves the unique most-specific active rule across action / Plan Offering / Sales Server / Product dimensions, fails closed on equal-specificity ambiguity, records explicit no-match, snapshots integer IRR override and discount-combination policy, and preserves immutable exact replay/conflict behavior. New resolutions require the current active agent/account/profile and expected current pricing-profile code.

This worker does **not** integrate the resolver into Quote or change Promotions lifecycle/semantics. End-to-end Quote consumption remains a separate bounded step.