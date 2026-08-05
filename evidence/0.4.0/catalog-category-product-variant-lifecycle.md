# Phase 0.4 catalog category/product/variant lifecycle

Status: **Implementation Green; evidence-head verification pending**

## Accepted implementation boundary

This bounded increment implements localized Category, Product and optional Variant identity/lifecycle foundations for `CAT-001` and the Product identity portion of `CAT-002`.

Implementation SHA:

- `f907fc463ff809dd2f5f69d1433e9c326f8574b0`

Mandatory standard CI:

- workflow: `CI`
- run ID: `31047128887`
- run number: `802`
- conclusion: `success`

Automated suite result extracted from `tests/test.log` in the retained test artifact:

- **205 tests passed**
- **1006 assertions**
- failures: `0`
- errors: `0`
- skipped: `0`
- duration reported by PHPUnit: `6.79s`

## Scope intentionally excluded

The following are not part of this bounded increment and are not claimed complete:

- Product/Variant price truth; server-specific price belongs to Plan Offerings and customer/agent quote resolution remains Phase `0.5.0`;
- Product images and media lifecycle;
- Plan Offerings, options, operation/add-on packages and typed service modes;
- panel connections, service targets, capability discovery, capacity, custom plans and trials;
- Order, payment, provisioning and service state.

Variants remain optional because the authoritative specification does not require every orderable Product to own a Variant row.

## Implemented controls

### Domain and lifecycle

- explicit `draft -> active -> archived` state machines with archived terminal behavior;
- Product `hidden <-> visible` transitions allowed only while Product and Category are active;
- Product archival requires hidden visibility and no non-archived Variant;
- Category archival requires no non-archived child Category or Product;
- Category hierarchy cycle prevention through locked ancestor traversal;
- Product category movement restricted to hidden draft Products;
- localized required Persian name, optional English name and localized descriptions;
- normalized immutable Category/Product/Variant codes and globally unique Variant SKU;
- deterministic sort order and optimistic expected-version conflict detection.

### Persistence and concurrency

- separate `product_categories`, `products` and `product_variants` tables;
- entity-specific FK-backed append-only history tables;
- MariaDB uniqueness, state, visibility and version constraints;
- database Trigger enforcement for direct Category self-parent writes;
- row locks for actor, target, ancestors and dependency checks;
- no nullable-parent unique index that relies on ambiguous MariaDB `NULL` uniqueness behavior;
- no generic `entity_type/entity_id` history without referential integrity.

### Authorization, replay and audit

- current `catalog.manage` authorization before every privileged mutation;
- active Administrator row lock and authorization revalidation inside the transaction;
- request-fingerprint exact replay returning the recorded receipt;
- same fingerprint with different payload/target fails closed as conflict;
- mandatory reason, reason code and correlation ID;
- safe audit/history projections contain structural IDs, state, visibility, version, sort order and content hashes only;
- raw localized names/descriptions, credentials, secrets and sensitive identity values are absent from audit safe-data.

### Access seed ownership

- existing Phase `0.3.0` `IdentityAccessFoundationSeeder` remains unchanged;
- Phase `0.4.0` adds `CatalogAccessFoundationSeeder` for `catalog.view` and `catalog.manage`;
- `sales_content` receives Catalog management and the four existing operational roles receive Catalog view;
- `DatabaseSeeder` executes Identity/ACL seed first and Catalog access seed second.

## Code and test map

Domain:

- `app/Modules/Catalog/Domain/CatalogState.php`
- `app/Modules/Catalog/Domain/ProductVisibility.php`
- `app/Modules/Catalog/Domain/CatalogCode.php`
- `app/Modules/Catalog/Domain/ProductSku.php`
- `app/Modules/Catalog/Domain/CatalogText.php`

Application and infrastructure:

- `app/Modules/Catalog/Application/CatalogChangeContext.php`
- `app/Modules/Catalog/Application/CatalogMutationAudit.php`
- `app/Modules/Catalog/Application/CatalogMutationExecutor.php`
- `app/Modules/Catalog/Application/ProductCategoryService.php`
- `app/Modules/Catalog/Application/ProductService.php`
- `app/Modules/Catalog/Application/ProductVariantService.php`
- typed record and bounded operation/support classes under `app/Modules/Catalog/Application/`
- `app/Modules/Catalog/Infrastructure/CatalogServiceProvider.php`
- `bootstrap/providers.php`

Persistence and seed:

- `database/migrations/2026_08_05_001400_create_product_catalog_foundation.php`
- `database/seeders/CatalogAccessFoundationSeeder.php`
- `database/seeders/DatabaseSeeder.php`

Tests:

- `tests/Unit/Modules/Catalog/CatalogDomainTest.php`
- `tests/Feature/ProductCatalogFoundationMigrationTest.php`
- `tests/Feature/CatalogLifecycleServicesTest.php`
- complete pre-existing regression suite.

## Mandatory job results

| Job | Result |
|---|---|
| Repository preflight | success |
| Secret scan | success |
| PHP static quality: Pint | success |
| PHP static quality: PHPStan/Larastan | success |
| Forbidden-pattern policy | success |
| Architecture policy | success |
| Composer strict validation | success |
| Dependency audit | success |
| License policy | success |
| MariaDB/authenticated Redis integration suite | success |

## Retained artifacts

| Artifact | Artifact ID | Digest |
|---|---:|---|
| `preflight-evidence-31047128887` | `8946886834` | `sha256:f54517f01f46da4343f2e0a97b53ab93e9454501834438b53c1d95219bebc32e` |
| `gitleaks-results.sarif` | `8946893992` | `sha256:b4e046c083f997beeef48fb957b8e5dc8d181189483b092f1242492910681c40` |
| `static-evidence-31047128887` | `8946914038` | `sha256:cd9a8d09bfcd9ba525ecea7ada06be867765047e898fa976170a75161e8f1d9b` |
| `dependency-evidence-31047128887` | `8946921414` | `sha256:cdeafe88ea74535a0353fef0214047d5affc420c6a78e4c17aee00d944f1f584` |
| `test-evidence-31047128887` | `8946934110` | `sha256:75f2e65aa6bb946d4bf4bbafc98c09036240717fece9212eeb34c36dd291f756` |

## Failure history and root-cause corrections

The increment was not marked complete during failed candidates.

### Run `31045276013` / #800

- MariaDB rejected a `CHECK` referencing the `AUTO_INCREMENT` Category ID;
- Pint identified formatting differences.

Correction:

- database-level self-parent enforcement moved to insert/update Triggers compatible with MariaDB;
- exact Pint formatting applied.

### Run `31046437854` / #801

- the complete suite reached 204 passing tests but one existing Phase 0.3 seed test failed because Catalog permissions had been added to the Identity/ACL seeder;
- PHPStan reported unprovable property access on generic query objects.

Correction:

- restored the Phase 0.3 seeder unchanged and introduced a phase-owned Catalog access seeder;
- introduced typed Category/Product/Variant query records and explicit conversion at database boundaries.

Run #802 verified both corrections without weakening the catalog invariants.

## Evidence-head gate

This document records the Green implementation boundary. The commit containing this evidence must pass the same standard CI before Issue #7's first checkbox is marked complete. Issue #7 and Draft PR #6 retain the exact evidence-head SHA and final CI run after that gate succeeds.
