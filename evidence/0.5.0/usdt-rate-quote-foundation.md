# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Evidence

**Status:** Contract Revision 2 implementation-verified Worker evidence candidate; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `USDT-001`, partial `USDT-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`.  
**Corrected implementation head:** `64a5da5414057064f6da62b05d1c02fc6ad9635d`.  
**Implementation CI:** `31313380720` / `#1358` — all five mandatory jobs successful.  
**Full suite:** **435 tests / 2724 assertions**.  
**W-007 focused suites:** **10 tests / 101 assertions**.  
**Provider-contract suite:** `UsdtRateProviderContractTest` **7 / 44**.  
**Immutable quote/auth/DB suite:** `UsdtRateQuoteFoundationTest` **3 / 57**.  
**BUY-002 regression:** `QuotePricingSnapshotTest` **8 / 63**.  
**Implementation artifact:** `test-evidence-31313380720`, ID `9038041663`, size `128922` bytes.  
**Independent artifact SHA-256:** `8c786a0226153c8c9edd2e6fa85721b51b4b9772e8e977bc4b79447a86427953` — exact match to the GitHub Actions uploader digest.

## Bounded Revision 2 outcome

This increment implements the common Direct-USDT-on-BEP20 rate/amount-quote foundation with a runnable manual provider and a runnable verified Nobitex public-market provider. It intentionally does **not** implement or accept a runnable Tetherland integration, and it does **not** claim complete `USDT-002`.

The common provider/result/policy layer normalizes source identity, fixed-precision IRR-per-USDT rate, fetch time and SHA-256 provider-response provenance. Provider selection is deterministic from configured priority, with bounded freshness, minimum/maximum sanity bounds, cross-source divergence control, circuit-breaker state and an explicit emergency manual fallback policy.

All monetary conversion uses `bcmath` and fixed decimal strings. There is no monetary float. Margin is integer basis points. USDT amount calculation rounds up deterministically to configurable precision capped at six decimals.

## Verified runnable Nobitex contract

The runnable external adapter is limited to the current official Nobitex public market-statistics contract checked on 2026-08-09 from `https://apidocs.nobitex.ir/`:

- public `GET /market/stats`;
- fixed request `srcCurrency=usdt&dstCurrency=rls`;
- successful payload requires `status = ok` and `stats.usdt-rls`;
- `buy` uses `bestSell`, `sell` uses `bestBuy`, and `last` uses `latest`;
- `rls` is the explicit Rial destination used as the IRR-per-USDT rate representation.

The implementation exposes one fixed allowlisted HTTPS URL only: `https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls`. Callers cannot supply a URL or endpoint. TLS verification remains enabled, redirects are disabled, connection/request timeouts are bounded, declared/content body size is bounded, JSON depth/shape is bounded, non-success/malformed responses fail closed, and only the response SHA-256 is persisted as provenance.

## Tetherland boundary

`TetherlandUsdtRateProvider` is an explicit **no-network unavailable adapter shell**. Its `fetch()` method fails closed before any HTTP request with a stable unavailable error. The runtime default priority excludes Tetherland.

The previously tentative `https://api.tetherland.com/currencies` path, tentative response-shape handling and any claim of runnable Tetherland compatibility were removed. No Tetherland endpoint, authentication method, response schema or credential is guessed, requested, stored or exposed.

Focused proof verifies:

1. direct Tetherland use fails closed with no request sent;
2. a resolver configured only for Tetherland fails closed;
3. fallback occurs only when policy explicitly includes another verified provider, demonstrated with a verified-provider test double;
4. no silent manual fallback occurs unless the explicit emergency-manual policy is enabled.

The exact Tetherland contract/auth/schema remains a later dedicated task. Issue `#34` therefore remains open and PR `#37` references rather than closes it.

## Rate selection and fixed-precision proof

`UsdtRateProviderContractTest` **7 / 44** covers:

- manual normalized rate and deterministic fixed-precision arithmetic;
- official Nobitex `usdt-rls` normalization for buy/sell/last;
- Tetherland no-network unavailable behavior and explicit verified fallback;
- malformed, oversized, redirect and transport failure handling;
- deterministic primary/fallback ordering;
- stale-rate and sanity-bound rejection;
- divergence fail-closed behavior plus explicit emergency manual fallback;
- circuit-breaker open/cooldown behavior;
- tiny and large integer-IRR conversion boundaries and six-decimal round-up.

## Immutable destination and amount quote

Destination configuration stores only the public BEP20 address and non-secret policy/version metadata. It does not store private keys or signing secrets. Administrator mutation uses execution-time `payments.usdt.manage` authorization and immutable version rows with exact mutation replay/conflict behavior.

A new USDT amount quote consumes the accepted BUY-002 Quote through the existing read-only `QuoteService::current()` boundary. It requires the source Quote to be current/unexpired and positive integer IRR. It snapshots:

- source Quote identity, user and final integer-IRR amount;
- `BEP20` network;
- destination wallet code/version/public address/configuration hash;
- rate source, raw rate, margin basis points and final rate;
- exact rounded-up USDT amount and precision;
- rate fetch time, quote expiry, rate-age/quote-validity policy;
- provider-response hash and configuration-snapshot hash.

Exact creation replay returns the accepted historical USDT quote before re-reading current rate/destination/source validity. Materially changed same-key input conflicts. Later destination/rate changes do not reinterpret accepted history.

`UsdtRateQuoteFoundationTest` **3 / 57** proves management authorization, version/replay/conflict, BEP20 destination snapshot, immutable amount quote, exact replay, historical stability after destination/rate change, expired source Quote rejection, no Payment Intent/Ledger mutation, and DB update/delete/forgery rejection.

Existing `QuotePricingSnapshotTest` remains green at **8 / 63** on the same implementation run.

## Database integrity

Forward migration `database/migrations/2026_08_09_003600_create_usdt_rate_quote_foundation.php` adds only:

1. `usdt_destination_wallet_versions` — immutable versioned public BEP20 destination configuration;
2. `usdt_amount_quotes` — immutable BUY-002-bound payment-amount quote history.

Controls include FKs, bounded/explicit FK naming for MariaDB, unique identities, fixed network/address/source/rate/time/hash/snapshot checks, sequential destination-version guard, current-enabled destination join, source Quote/user/amount/validity join, deterministic exact-USDT DB recomputation, snapshot/hash verification, and update/delete denial triggers.

No existing applied migration is edited.

## Exact implementation validation

Implementation CI `31313380720` / `#1358` passed:

- Repository preflight;
- Secret scan;
- PHP static quality, including Pint, PHPStan, forbidden-pattern and architecture checks;
- MariaDB and Redis tests: **435 / 2724**;
- Dependency and license policy.

The retained test artifact contains JUnit, full test log, Clover coverage and sanitized dependency-service evidence. Its independently downloaded ZIP SHA-256 is `8c786a0226153c8c9edd2e6fa85721b51b4b9772e8e977bc4b79447a86427953`, matching the uploader digest exactly.

## Explicit deferred / unaccepted behavior

This evidence does **not** claim acceptance of:

- runnable Tetherland integration;
- complete `USDT-002`;
- `USDT-003` TXID submission, explorer/node lookup, confirmation verification or capture;
- Payment Intent creation or capture;
- Order creation or purchase authority;
- Wallet/Ledger effect or refund behavior;
- `PAY-001` eligibility/routing or W-005-owned surfaces;
- Zarinpal, NOWPayments, card-to-card or gift-card providers;
- provider-live credentials or protected workflows;
- provisioning or Service behavior;
- Telegram purchase UX;
- `composer.lock` changes;
- Phase `0.5.0` closure or production release acceptance.

## Evidence-head lifecycle

The implementation-to-evidence change set is documentation only. The exact final evidence/current head, its current merge-candidate five-job CI, retained artifact and independent digest are recorded on PR `#37` after the evidence-head run succeeds. This file intentionally does not self-reference a future evidence commit SHA.
