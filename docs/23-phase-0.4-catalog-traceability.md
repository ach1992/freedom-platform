# Phase 0.4 Catalog Increment Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`
Active issue: #7
Implementation SHA: `f907fc463ff809dd2f5f69d1433e9c326f8574b0`
Implementation CI: `31047128887` / run #802 — success

This supplement records the first bounded Phase 0.4 implementation. It does not claim the full `CAT-002` Plan Offering boundary or any later Phase requirement.

| Requirement | Implemented behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-001` | Localized Category create/update/order, explicit activate/archive, cycle and dependency guards, no historical deletion | `ProductCategoryService*`, catalog migration | `CatalogDomainTest`, `CatalogLifecycleServicesTest`, `ProductCatalogFoundationMigrationTest` | `evidence/0.4.0/catalog-category-product-variant-lifecycle.md` |
| `CAT-002` partial | Product commercial identity and optional Variant identity, lifecycle, visibility and category binding; no premature Offering price truth | `ProductService*`, `ProductVariantService*`, catalog migration | same Catalog suites | same evidence |
| `ACL-001` support | Catalog view/manage permissions attached through phase-owned idempotent access seed | `CatalogAccessFoundationSeeder` | sales-role/unauthorized Catalog feature test and full regression suite | same evidence |
| `ACL-002` | Permission checked before mutation and revalidated after active Administrator row lock inside transaction | `CatalogMutationExecutor`, `AdministratorPermissionAuthorizer` | authorized/unauthorized Catalog feature test | same evidence |
| `SEC-002` | Exact replay, conflicting-fingerprint rejection, lock/transaction/uniqueness barriers and mandatory mutation context | Catalog Application services and migration | lifecycle/replay/version/conflict tests | same evidence |
| `DAT-003` | Entity-specific append-only histories with FK integrity and safe structural audit projection | migration, `CatalogMutationAudit`, service history writers | migration/history/audit leakage tests | same evidence |
| `QUA-001` | Requirement annotations, unit/feature/migration tests, full regression, static/security/dependency gates and retained artifacts | all increment files and standard CI | 205 tests / 1006 assertions | run `31047128887` artifacts |

## Remaining Phase 0.4 work

- Plan Offerings and typed service modes;
- product options and operation/add-on packages;
- sales servers, encrypted panel connections and service targets;
- protocol/capability discovery and mapping;
- capacity, availability and disclosed fallback;
- custom plan calculation inputs;
- trials and abuse controls;
- Fake/Marzban/PasarGuard adapter contracts and remote uncertain-result adoption;
- phase-level closure audit.

Pricing/ledger/immutable quote resolution remains Phase 0.5 and Order/provisioning/service state remains Phase 0.6.
