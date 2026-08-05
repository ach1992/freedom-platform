# Phase 0.4 catalog category/product/variant lifecycle

Status: **Unverified implementation candidate**

## Scope

This bounded increment implements only localized Category, Product and optional Variant identity/lifecycle foundations for `CAT-001` and the Product identity portion of `CAT-002`.

It intentionally excludes:

- Product/Variant price truth; pricing belongs to Plan Offerings and Phase 0.5 quote resolution;
- Product images and media lifecycle;
- Plan Offerings, options/add-on packages, panels, targets, capacity, custom plans and trials;
- Order, payment and provisioning state.

## Candidate controls

- explicit `draft -> active -> archived` fail-closed lifecycle;
- Product `hidden <-> visible` transitions restricted to active Products in active Categories;
- Category hierarchy cycle prevention with locked ancestor traversal;
- dependency-safe archival and restricted Product category movement;
- optimistic expected-version checks plus row locks and database uniqueness/check constraints;
- current `catalog.manage` authorization before and inside every privileged transaction;
- request-fingerprint exact replay and payload-conflict rejection;
- entity-specific FK-backed append-only histories;
- safe audit payloads containing structural IDs/state/version/content hashes only;
- no raw localized description in audit/evidence.

## Candidate files

- `database/migrations/2026_08_05_001400_create_product_catalog_foundation.php`
- `app/Modules/Catalog/Domain/*`
- `app/Modules/Catalog/Application/*`
- `app/Modules/Catalog/Infrastructure/CatalogServiceProvider.php`
- `database/seeders/IdentityAccessFoundationSeeder.php`
- `bootstrap/providers.php`
- `tests/Unit/Modules/Catalog/CatalogDomainTest.php`
- `tests/Feature/ProductCatalogFoundationMigrationTest.php`
- `tests/Feature/CatalogLifecycleServicesTest.php`

## Verification pending

No completion claim is made. The following must be recorded after the exact implementation SHA passes the standard workflow:

- implementation SHA;
- CI run ID and run number;
- all job results;
- actual test and assertion counts from JUnit/test output;
- artifact names;
- any resolved failure and resulting SHA.

After this evidence file is updated with exact Green results, the evidence-head commit must pass its own final CI before the first Issue #7 checkbox can be marked complete.
