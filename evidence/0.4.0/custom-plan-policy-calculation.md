# Phase 0.4 Custom Plan Policy and Calculation Snapshot

Status: **Implementation Green; evidence-head verification pending**

## Accepted implementation boundary

This bounded increment implements the authoritative `CAT-005` custom-plan policy and calculation-snapshot boundary without entering Phase `0.5.0` resolved pricing/Quote or Phase `0.6.0` Order/provisioning.

Implementation SHA:

- `8e62867277acdd39cd1471ed3d454ef25520bef8`

Mandatory standard CI:

- workflow: `CI`
- run ID: `31071843621`
- run number: `833`
- conclusion: `success`

Automated suite result extracted from the retained JUnit and test log:

- **255 tests passed**
- **1267 assertions**
- failures: `0`
- errors: `0`
- skipped: `0`
- PHPUnit duration: `8.745748s`

## Implemented controls

### Offering-scoped policy

- one versioned custom-plan policy per Plan Offering;
- policy can be created or changed only while the Offering is draft and allows custom plans;
- explicit data-GB and day minimum, maximum and step rules;
- customer-selected and deterministic generated username modes;
- username length, separator and reserved-word policies;
- explicit customer/agent integer-IRR calculation components and minimum-order floor;
- authoritative tier/tag eligibility with explicit tag match semantics;
- configuration hash, expected-version conflict detection and append-only policy history.

### Calculation snapshot

- authoritative account, customer/agent identity, tier and tag reads inside the transaction;
- Offering and policy compatibility checked before calculation;
- normalized username availability checked against an authoritative registry;
- overflow-safe integer arithmetic only; no monetary float;
- immutable snapshot of policy ID/version/hash, actor type, eligibility hash, requested limits, normalized username and every calculation component;
- exact command replay returns the original calculation receipt;
- the same command key with a different payload fails closed;
- database formula constraints reject inconsistent direct inserts;
- calculation rows, validation rows and policy histories are append-only.

### Revalidation contract

- explicit `pre_payment` and `pre_provisioning` validation stages;
- operational Offering/route/capability verification is executed through `CustomPlanOperationalVerifier`;
- policy version/hash, eligibility and username availability are revalidated;
- each validation command is exact-replay-safe and immutable;
- no payment capture, Order creation, provisioning call or remote panel effect occurs in this increment.

### Authorization and audit

- policy mutations use the existing `catalog.manage` authorization and transaction revalidation;
- mutation receipts and audit history contain structural IDs, versions, hashes, reason and correlation data only;
- no credential, encrypted value, lookup key/hash, raw panel configuration or sensitive identity value is written to evidence or audit safe-data.

## Scope intentionally excluded

- promotion, discount, referral or external customer/agent override resolution;
- immutable Quote aggregate and quote-expiry behavior;
- ledger entries, payment providers or payment-proof handling;
- Order state, provisioning orchestration and Service lifecycle;
- remote username creation or any Panel API call;
- Telegram customer/admin presentation.

The calculation result is a custom-plan input/result snapshot for later authoritative pricing and ordering boundaries; it is not a Quote, Order or payment authorization.

## Code and test map

Domain and application:

- `app/Modules/Catalog/Domain/CustomPlanPolicyDefinition.php`
- `app/Modules/Catalog/Domain/CustomPlanPricing.php`
- `app/Modules/Catalog/Domain/CustomPlanActorType.php`
- `app/Modules/Catalog/Domain/CustomPlanUsernameMode.php`
- `app/Modules/Catalog/Domain/CustomPlanValidationStage.php`
- `app/Modules/Catalog/Application/CustomPlanPolicyService.php`
- `app/Modules/Catalog/Application/CustomPlanCalculator.php`
- `app/Modules/Catalog/Application/CustomPlanEligibility.php`
- `app/Modules/Catalog/Application/CustomPlanArithmetic.php`
- `app/Modules/Catalog/Application/CustomPlanUsernameNormalizer.php`
- `app/Modules/Catalog/Application/CustomPlanOperationalVerifier.php`
- `app/Modules/Catalog/Infrastructure/DatabaseCustomPlanOperationalVerifier.php`
- `app/Modules/Catalog/Infrastructure/DatabaseServiceUsernameAvailability.php`
- `app/Modules/Catalog/Infrastructure/CatalogServiceProvider.php`

Persistence:

- `database/migrations/2026_08_06_002000_create_custom_plan_foundation.php`
- `database/migrations/2026_08_06_002100_correct_custom_plan_calculation_guard.php`

Tests:

- `tests/Unit/Modules/Catalog/CustomPlanDomainTest.php`
- `tests/Feature/CustomPlanCalculationTest.php`
- complete pre-existing regression suite.

## Mandatory job results

| Job | Result |
|---|---|
| Repository preflight | success |
| Secret scan | success |
| Pint formatting | success |
| PHPStan/Larastan | success |
| Forbidden-pattern policy | success |
| Architecture policy | success |
| Composer strict validation | success |
| Dependency audit | success |
| License policy | success |
| MariaDB/authenticated Redis integration suite | success |

## Retained artifacts

| Artifact | Artifact ID | Digest |
|---|---:|---|
| `preflight-evidence-31071843621` | `8955914555` | `sha256:b6fc22626a6eae6457c9c37fd3982ebf59fe9221bb9ac969feeee9c68f85a9d7` |
| `gitleaks-results.sarif` | `8955918662` | `sha256:0b0c4a6d6219c1d3cd6e82e7e3b2614670b8fa71960bf98105107fd273f90296` |
| `static-evidence-31071843621` | `8955942226` | `sha256:dc0d70c24558c1b8f07ae2ee8509316b2ec689d9ac53bcd6739deb7888ea6f20` |
| `dependency-evidence-31071843621` | `8955947098` | `sha256:3157ccde8d63514358dd03b9344236fe5839d0c28099a9d67f28e4363183398e` |
| `test-evidence-31071843621` | `8955927141` | `sha256:37237a35674fea2c28db84c990768f8e2305b098805ce0b4c1bb05e004c5f7b0` |

## Evidence-head gate

The commit containing this evidence and its traceability document must pass the same complete CI workflow before the Custom Plan checkbox in Issue #7 is marked complete. Issue #7 and Draft PR #6 will retain the exact evidence-head SHA, final CI run, test/assertion count and final artifact digest after that gate succeeds.
