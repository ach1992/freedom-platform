# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Evidence

**Status:** Accepted bounded USDT rate / immutable amount-quote foundation, integrated in Phase `0.5.0`.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `USDT-001`, partial `USDT-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**Accepted PR:** `#37` (merged with history-preserving `merge`).  
**Accepted integration SHA:** `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584`.  
**Post-merge integration CI:** `31320787076` / `#1382` — all five mandatory jobs successful.  
**Post-merge full suite:** **447 tests / 2899 assertions**.  
**Post-merge artifact:** `test-evidence-31320787076`, ID `9040138207`, size `130763` bytes.  
**Post-merge artifact SHA-256:** `a53cbd584bf7ab58a5a25c77762eb4ae712da8e36ddd7f7d3102b0abe0f198cc` — exact match to the GitHub Actions uploader digest.  
**Historical dispatch base:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`.  
**Historical corrected implementation head:** `c8cafbcdb53b69d94080f6ee7d486cff2dc74fe5`.  
**Historical implementation CI:** `31316814017` / `#1367` — **436 tests / 2735 assertions**.  
**W-007 focused suites:** **11 tests / 112 assertions**.  
**Provider-contract suite:** `UsdtRateProviderContractTest` **7 / 44**.  
**Immutable quote/auth/DB suite:** `UsdtRateQuoteFoundationTest` **3 / 57**.  
**Standard-seed authorization suite:** `UsdtStandardSeedAuthorizationTest` **1 / 11**.  
**BUY-002 regression:** `QuotePricingSnapshotTest` **8 / 63**.  
**Historical implementation artifact:** `test-evidence-31316814017`, ID `9039007750`, size `129026` bytes.  
**Historical implementation artifact SHA-256:** `b5d8b9ee82dae2e65cc0f6f3998980d45033d227a22d7d3319dbc890806a0b8f` — exact match to the GitHub Actions uploader digest.

## Bounded Revision 2 outcome

This increment implements the common Direct-USDT-on-BEP20 rate/amount-quote foundation with a runnable manual provider and a runnable verified Nobitex public-market provider. It intentionally does **not** implement or accept a runnable Tetherland integration, and it does **not** claim complete `USDT-002`.

The common provider/result/policy layer normalizes source identity, fixed-precision IRR-per-USDT rate, fetch time and SHA-256 provider-response provenance. Provider selection is deterministic from configured priority, with bounded freshness, minimum/maximum sanity bounds, cross-source divergence control, circuit-breaker state and an explicit emergency manual fallback policy.

All monetary conversion uses `bcmath` and fixed decimal strings. There is no monetary float. Margin is integer basis points. USDT amount calculation rounds up deterministically to configurable precision capped at six decimals.

## Verified runnable Nobitex contract

The runnable external adapter is limited to the current official Nobitex public market-statistics contract checked on 2026-08-09 from `https://apidocs.nobitex.ir/`:

- public `GET /market/stats`, no token required;
- current official example host `apiv2.nobitex.ir`;
- fixed request `srcCurrency=usdt&dstCurrency=rls`;
- successful payload requires `status = ok` and `stats.usdt-rls`;
- `buy` uses `bestSell`, `sell` uses `bestBuy`, and `last` uses `latest`;
- `rls` is the explicit Rial destination used as the IRR-per-USDT rate representation.

The implementation exposes one fixed allowlisted HTTPS URL only: `https://apiv2.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls`. Callers cannot supply a URL or endpoint. TLS verification remains enabled, redirects are disabled, connection/request timeouts are bounded, declared/content body size is bounded, JSON depth/shape is bounded, non-success/malformed responses fail closed, and only the response SHA-256 is persisted as provenance.

## Tetherland boundary

`TetherlandUsdtRateProvider` is an explicit **no-network unavailable adapter shell**. Its `fetch()` method fails closed before any HTTP request with a stable unavailable error. The runtime default priority excludes Tetherland.

The previously tentative `https://api.tetherland.com/currencies` path, tentative response-shape handling and any claim of runnable Tetherland compatibility were removed. No Tetherland endpoint, authentication method, response schema or credential is guessed, requested, stored or exposed.

Focused proof verifies:

1. direct Tetherland use fails closed with no request sent;
2. a resolver configured only for Tetherland fails closed;
3. fallback occurs only when policy explicitly includes another verified provider, demonstrated with a verified-provider test double;
4. no silent manual fallback occurs unless the explicit emergency-manual policy is enabled.

The exact Tetherland contract/auth/schema remains a later dedicated task. Issue `#34` therefore remains open; PR `#37` was intentionally non-closing and its accepted merge does not close that gap.

## Rate selection and fixed-precision proof

`UsdtRateProviderContractTest` **7 / 44** covers manual/Nobitex normalization, Tetherland no-network unavailable behavior, explicit verified fallback, malformed/oversized/redirect/transport failures, freshness, bounds, divergence, emergency manual fallback, circuit breaker, and fixed-precision boundary arithmetic.

## Destination authorization and standard seed proof

Destination configuration stores only the public BEP20 address and non-secret policy/version metadata. Administrator mutation uses execution-time `payments.usdt.manage` authorization and immutable version rows with exact mutation replay/conflict behavior.

The standard install/seed path now invokes `UsdtAccessFoundationSeeder` from `DatabaseSeeder` immediately after `IdentityAccessFoundationSeeder`. This provisions the `payments.usdt.manage` high-risk permission and the intended grant to the active `finance` role in the normal repository seed chain rather than requiring a one-off USDT seed command.

`UsdtStandardSeedAuthorizationTest` **1 / 11** runs the real `DatabaseSeeder` and proves:

- `payments.usdt.manage` exists with the expected Payments/high-risk identity;
- the active `finance` role receives that permission through `role_permissions`;
- an active **non-owner** administrator assigned the finance role can call `UsdtDestinationWalletService::configure()` successfully;
- an active non-owner administrator without the grant is denied and creates no destination row.

The test does not use owner bypass for the successful management path, and no owner, permission-resolution, role-assignment or administrator-authorization semantics are weakened.

## Immutable destination and amount quote

A new USDT amount quote consumes the accepted BUY-002 Quote through the existing read-only `QuoteService::current()` boundary. It requires the source Quote to be current/unexpired and positive integer IRR. It snapshots source Quote identity/user/final integer-IRR amount, `BEP20` network, destination wallet identity/version/public address/configuration hash, rate source/raw rate/margin/final rate, exact rounded-up USDT amount/precision, rate fetch/expiry policy, provider-response hash and configuration-snapshot hash.

Exact creation replay returns the accepted historical USDT quote before re-reading current rate/destination/source validity. Materially changed same-key input conflicts. Later destination/rate changes do not reinterpret accepted history.

`UsdtRateQuoteFoundationTest` **3 / 57** proves destination version/replay/conflict, BEP20 snapshot, immutable amount quote, exact replay, historical stability, expired source Quote rejection, no Payment Intent/Ledger mutation, and DB update/delete/forgery rejection. Existing `QuotePricingSnapshotTest` remains green at **8 / 63** on the same implementation run.

## Database integrity

Forward migration `database/migrations/2026_08_09_003600_create_usdt_rate_quote_foundation.php` adds only `usdt_destination_wallet_versions` and `usdt_amount_quotes`. Controls include FKs, bounded/explicit FK naming for MariaDB, unique identities, network/address/source/rate/time/hash/snapshot checks, sequential destination-version guard, current-enabled destination join, source Quote/user/amount/validity join, deterministic exact-USDT DB recomputation, snapshot/hash verification, and update/delete denial triggers. No existing applied migration is edited.

## Historical implementation validation

Historical implementation CI `31316814017` / `#1367` passed:

- Repository preflight;
- Secret scan;
- PHP static quality, including Pint, PHPStan, forbidden-pattern and architecture checks;
- MariaDB and Redis tests: **436 / 2735**;
- Dependency and license policy.

The retained historical test artifact contains JUnit, full test log, Clover coverage and sanitized dependency-service evidence. Its independently downloaded ZIP SHA-256 is `b5d8b9ee82dae2e65cc0f6f3998980d45033d227a22d7d3319dbc890806a0b8f`, matching the uploader digest exactly.

## Explicit deferred / unaccepted behavior

This evidence does **not** claim acceptance of runnable Tetherland integration, complete `USDT-002`, `USDT-003` TXID/chain verification/capture, Payment Intent or capture, Order/purchase authority, Wallet/Ledger effects, `PAY-001`/W-005 routing, Zarinpal/NOWPayments/C2C/Gift Card, provider-live credentials/workflows, provisioning/Service, Telegram purchase UX, `composer.lock` changes, Phase `0.5.0` closure or production release acceptance.

## Acceptance lifecycle

The implementation-to-evidence change set after `c8cafbcdb53b69d94080f6ee7d486cff2dc74fe5` was documentation only. PR `#37` was revalidated on current target before its history-preserving merge, then accepted at integration SHA `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584` after post-merge CI `31320787076` / `#1382` passed all five mandatory jobs. The retained post-merge artifact `test-evidence-31320787076` (ID `9040138207`) has independently verified SHA-256 `a53cbd584bf7ab58a5a25c77762eb4ae712da8e36ddd7f7d3102b0abe0f198cc`.
