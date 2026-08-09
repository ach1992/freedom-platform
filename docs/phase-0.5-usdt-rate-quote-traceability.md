# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Traceability

**Status:** Contract Revision 2 implementation-verified Worker evidence candidate; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `USDT-001`, partial `USDT-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `a7876668492c115ce2eba1b7194c83ac169ce8a7`.  
**Corrected implementation head:** `64a5da5414057064f6da62b05d1c02fc6ad9635d`.  
**Implementation CI:** `31313380720` / `#1358` — all five mandatory jobs successful, **435 tests / 2724 assertions**.  
**Evidence:** `evidence/0.5.0/usdt-rate-quote-foundation.md`.

## Requirement-to-proof map

| Requirement / invariant | Worker proof | Deliberately deferred |
|---|---|---|
| bounded `USDT-001` destination/amount quote | versioned public BEP20 destination config plus immutable BUY-002-bound USDT amount quote | TXID/chain verification and capture are `USDT-003`/later |
| partial `USDT-002` provider contract | typed provider/result/policy; runnable manual; verified runnable Nobitex public `GET /market/stats`; deterministic controls | runnable Tetherland and complete `USDT-002` remain open |
| Tetherland fail-closed | no-network adapter shell throws unavailable; default priority excludes Tetherland; focused test proves no request | exact official Tetherland endpoint/auth/schema later dedicated task |
| deterministic source selection | explicit priority, no DB iteration order, source code uniqueness, fixed side mapping | future sources must enter through same typed contract and verification gate |
| freshness | injected Clock plus `maxAgeSeconds`; stale and future-dated rate evidence rejected | provider-side server timestamp semantics are not invented where official contract does not provide one |
| sanity bounds | fixed decimal min/max rate policy checked before use | policy values remain deployment configuration |
| cross-source divergence | fixed-precision bcmath basis-point comparison; over-threshold sources fail closed | operational policy may later define additional approved source combinations |
| circuit breaker | bounded failure threshold/cooldown in cache; open source skipped deterministically | shared provider-health control plane remains later integration |
| emergency manual fallback | manual source is used only when explicitly enabled and present | no implicit/manual silent fallback |
| `DAT-002` | Quote amount remains integer IRR; rate/USDT use fixed decimal strings and bcmath; integer basis points; no monetary float | none for this bounded calculation |
| margin/rounding | final IRR/USDT rate derives deterministically; round-up precision is 0..6, default 6 | alternative rounding policy requires explicit future version |
| destination identity/version | public address/network/configuration hash/version snapshotted; no caller URL or destination override | private keys/signing remain explicitly forbidden |
| BUY-002 read-only binding | existing `QuoteService::current()` validates authoritative current source Quote; no Quote persistence/service change | Order/purchase authority remains Phase 0.6 |
| immutable replay | quote key + request payload hash; existing row returned before live config/rate lookup; changed source input conflicts | later payment intent must consume accepted quote without reinterpretation |
| historical stability | accepted destination/rate/margin/exact amount/timestamps/hashes are immutable snapshots | no mutable refresh of accepted quote |
| `DAT-003` | forward `003600` schema, FKs, unique/check constraints, insert joins, snapshot/hash checks, deterministic DB exact-USDT recomputation, immutable triggers | chain/provider transaction tables not introduced |
| `SEC-001` external network | fixed HTTPS Nobitex URL, TLS verification enabled, redirect disabled, bounded connect/request/body parsing, no caller-controlled URL | Tetherland remains no-network until verified |
| `SEC-002` authorization/secrets | destination mutation re-authorizes `payments.usdt.manage`; only public address/non-secret policy stored; no provider secrets | provider-live credentials/workflows remain excluded |
| `QUA-001` | implementation exact-head five-job CI, full/focused regression suites and independently hashed retained artifact | evidence head requires its own exact current merge-candidate CI |

## Provider contract boundary

### Manual

`ManualUsdtRateProvider` is runnable only when a fixed configured decimal rate is supplied. Its response hash derives from non-secret normalized source/rate/side/time inputs. It is not silently selected unless policy explicitly permits emergency manual fallback.

### Nobitex

Official Nobitex API documentation checked 2026-08-09 at `https://apidocs.nobitex.ir/` documents public `GET /market/stats`, accepts `srcCurrency=usdt` and `dstCurrency=rls`, and returns `status`, `stats.<market>.bestSell`, `bestBuy` and `latest`.

The adapter fixes the request to `https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls`. `buy` maps to `bestSell`, `sell` to `bestBuy`, `last` to `latest`. The destination `rls` is used directly as the integer/fixed-decimal IRR-per-USDT representation.

### Tetherland

No official exact endpoint/auth/schema was accepted for this Worker. `TetherlandUsdtRateProvider` has no HTTP client/URL and always fails closed. The removed tentative `/currencies` path is not part of the accepted contract. No Tetherland compatibility claim or credentials are present.

## Storage ownership

Revision 2 adds only USDT-owned tables through `database/migrations/2026_08_09_003600_create_usdt_rate_quote_foundation.php`:

1. `usdt_destination_wallet_versions`;
2. `usdt_amount_quotes`.

No existing applied migration, shared Payments registration, PAY-001 surface, Quote persistence, Wallet/Ledger, Order, Provisioning or Service module is changed.

## Test traceability

Implementation CI `31313380720` / `#1358`:

- full repository suite: **435 tests / 2724 assertions**;
- `UsdtRateProviderContractTest`: **7 / 44**;
- `UsdtRateQuoteFoundationTest`: **3 / 57**;
- combined W-007 focused: **10 / 101**;
- existing `QuotePricingSnapshotTest`: **8 / 63**;
- zero failures/errors/skips in these suites.

Provider suite covers manual/Nobitex normalization, Tetherland unavailable no-network behavior, explicit verified fallback, malformed/oversized/redirect/transport failures, freshness, bounds, divergence, emergency manual fallback, circuit breaker and fixed-precision boundary arithmetic.

Quote suite covers administrator authorization, BEP20 destination version/replay/conflict, immutable amount snapshot, exact replay, historical stability, source Quote expiry rejection, no Payment Intent/Ledger effect and MariaDB forged/update/delete rejection.

## Evidence identity

Implementation artifact `test-evidence-31313380720`, ID `9038041663`, size `128922` bytes, retained 30 days. Independent downloaded ZIP SHA-256: `8c786a0226153c8c9edd2e6fa85721b51b4b9772e8e977bc4b79447a86427953`, exactly matching the uploader digest.

## Explicit non-claims

Revision 2 does **not** claim:

- runnable Tetherland integration;
- complete `USDT-002`;
- `USDT-003` TXID/explorer/confirmation/capture;
- Payment Intent or capture;
- Order or purchase authority;
- Wallet/Ledger mutation/refund;
- `PAY-001` or W-005-owned routing/eligibility;
- Zarinpal/NOWPayments/C2C/Gift Card;
- provider-live secrets/workflows;
- provisioning/Service or Telegram UX;
- `composer.lock` changes;
- Phase `0.5.0` closure or release readiness.

Issue `#34` remains open for the Tetherland/full-`USDT-002` gap and PR `#37` is deliberately non-closing.
