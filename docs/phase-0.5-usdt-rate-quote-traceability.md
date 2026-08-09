# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Traceability

**Status:** Contract Revision 2 implementation-verified Worker evidence candidate; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `USDT-001`, partial `USDT-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`.  
**Corrected implementation head:** `c8cafbcdb53b69d94080f6ee7d486cff2dc74fe5`.  
**Implementation CI:** `31316814017` / `#1367` — all five mandatory jobs successful, **436 tests / 2735 assertions**.  
**Evidence:** `evidence/0.5.0/usdt-rate-quote-foundation.md`.

## Requirement-to-proof map

| Requirement / invariant | Worker proof | Deliberately deferred |
|---|---|---|
| bounded `USDT-001` destination/amount quote | versioned public BEP20 destination config plus immutable BUY-002-bound USDT amount quote | TXID/chain verification and capture are `USDT-003`/later |
| partial `USDT-002` provider contract | typed provider/result/policy; runnable manual; verified runnable Nobitex public `GET /market/stats`; deterministic controls | runnable Tetherland and complete `USDT-002` remain open |
| Tetherland fail-closed | no-network adapter shell throws unavailable; default priority excludes Tetherland; focused test proves no request | exact official Tetherland endpoint/auth/schema later dedicated task |
| deterministic source selection | explicit priority, no DB iteration order, source code uniqueness, fixed side mapping | future sources must enter through same typed contract and verification gate |
| freshness / bounds / divergence | Clock/max-age, fixed decimal min/max, bcmath divergence and explicit emergency fallback | operational policy remains deployment-owned |
| circuit breaker | bounded failure threshold/cooldown in cache; open source skipped deterministically | shared provider-health control plane remains later integration |
| `DAT-002` | integer IRR plus fixed decimal strings/bcmath and integer basis points; no monetary float | none for this bounded calculation |
| margin/rounding | deterministic rate adjustment and round-up precision 0..6 | alternative rounding policy requires explicit future version |
| destination identity/version | public address/network/configuration hash/version snapshotted; no private key or caller destination override | signing remains outside this boundary |
| standard deployment permission provisioning | `DatabaseSeeder` invokes `UsdtAccessFoundationSeeder` immediately after Identity/Access; standard seed produces `payments.usdt.manage` and finance grant | future role-policy changes require their own reviewed migration/seed semantics |
| non-owner authorization | standard-seed regression assigns the active finance role to a non-owner admin and proves `configure()` succeeds; ungranted active non-owner is denied | owner bypass remains unchanged and is not used as proof of the finance grant |
| BUY-002 read-only binding | existing `QuoteService::current()` validates authoritative current source Quote; no Quote persistence/service change | Order/purchase authority remains Phase 0.6 |
| immutable replay / history | quote key + request hash; accepted row returned before live re-resolution; changed input conflicts; snapshots remain immutable | downstream payment authority remains later |
| `DAT-003` | forward `003600` schema, FKs, unique/check constraints, insert joins, snapshot/hash checks, DB exact-USDT recomputation and immutable triggers | chain/provider transaction tables not introduced |
| `SEC-001` external network | fixed current-host HTTPS Nobitex URL, TLS verification, no redirects, bounded request/body parsing, no caller URL | Tetherland remains no-network until verified |
| `SEC-002` authorization/secrets | execution-time `payments.usdt.manage`; standard finance grant proven; only public wallet data stored; no provider secrets | provider-live credentials/workflows remain excluded |
| `QUA-001` | implementation exact-head five-job CI, full/focused suites and independently hashed retained artifact | evidence head requires its own exact current merge-candidate CI |

## Provider contract boundary

### Manual

`ManualUsdtRateProvider` is runnable only when a fixed configured decimal rate is supplied and is not silently selected unless explicit emergency-manual policy permits it.

### Nobitex

Official current Nobitex API documentation checked 2026-08-09 documents public `GET /market/stats`, no token requirement, current example host `apiv2.nobitex.ir`, `srcCurrency=usdt`, `dstCurrency=rls`, and `status` / `stats.<market>.bestSell|bestBuy|latest` response fields. The adapter fixes the request to `https://apiv2.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls`; buy maps to `bestSell`, sell to `bestBuy`, last to `latest`.

### Tetherland

No official exact endpoint/auth/schema was accepted. `TetherlandUsdtRateProvider` has no HTTP client/URL and always fails closed. No Tetherland compatibility claim or credentials are present.

## Standard seed and authorization path

`UsdtAccessFoundationSeeder` defines `payments.usdt.manage` and grants it to the intended `finance` role. The corrected standard repository seed chain calls this seeder directly after `IdentityAccessFoundationSeeder`, ensuring the role and access-control foundation exists before the USDT permission/grant is provisioned.

`UsdtStandardSeedAuthorizationTest` executes `DatabaseSeeder`, not an explicit USDT seed. It verifies the permission row, active finance role, role-permission grant, successful destination configuration by an active **non-owner** finance-role administrator, and denial for an active non-owner without the grant. The production `AdministratorPermissionAuthorizer` is used unchanged. Owner bypass, role assignment and permission/override semantics are not weakened.

## Storage and modification ownership

Revision 2 adds USDT-owned tables through `database/migrations/2026_08_09_003600_create_usdt_rate_quote_foundation.php`: `usdt_destination_wallet_versions` and `usdt_amount_quotes`.

The required correction additionally changes the standard `database/seeders/DatabaseSeeder.php` chain only to invoke the already bounded `UsdtAccessFoundationSeeder`. No existing applied migration, shared Payments registration, PAY-001 surface, Quote persistence, Wallet/Ledger, Order, Provisioning or Service module is changed.

## Test traceability

Implementation CI `31316814017` / `#1367`:

- full repository suite: **436 tests / 2735 assertions**;
- `UsdtRateProviderContractTest`: **7 / 44**;
- `UsdtRateQuoteFoundationTest`: **3 / 57**;
- `UsdtStandardSeedAuthorizationTest`: **1 / 11**;
- combined W-007 focused: **11 / 112**;
- existing `QuotePricingSnapshotTest`: **8 / 63**;
- zero failures/errors/skips in these suites.

## Evidence identity

Implementation artifact `test-evidence-31316814017`, ID `9039007750`, size `129026` bytes, retained 30 days. Independent downloaded ZIP SHA-256: `b5d8b9ee82dae2e65cc0f6f3998980d45033d227a22d7d3319dbc890806a0b8f`, exactly matching the uploader digest.

## Explicit non-claims

Revision 2 does **not** claim runnable Tetherland integration, complete `USDT-002`, `USDT-003`, Payment Intent/capture, Order/purchase authority, Wallet/Ledger mutation/refund, `PAY-001` or W-005 routing/eligibility, Zarinpal/NOWPayments/C2C/Gift Card, provider-live secrets/workflows, provisioning/Service, Telegram purchase UX, `composer.lock` changes, Phase `0.5.0` closure or release readiness.

Issue `#34` remains open for the Tetherland/full-`USDT-002` gap and PR `#37` is deliberately non-closing.
