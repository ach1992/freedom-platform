# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Risk Note

**Status:** bounded Worker Contract Revision 2 risk disposition; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Corrected implementation head:** `64a5da5414057064f6da62b05d1c02fc6ad9635d`.  
**Implementation CI:** `31313380720` / `#1358` — all five mandatory jobs successful, **435 tests / 2724 assertions**.  
**Evidence:** `evidence/0.5.0/usdt-rate-quote-foundation.md`.  
**Traceability:** `docs/phase-0.5-usdt-rate-quote-traceability.md`.

## Risk disposition

This increment is High risk for financial/pricing integrity, external-network security and configuration authorization. Contract Revision 2 narrows the external integration boundary rather than accepting an unverified Tetherland contract. The accepted runnable provider set is manual plus verified Nobitex public market data; Tetherland remains fail-closed/no-network and complete `USDT-002` remains open.

| Risk | Control in Revision 2 | Residual / exit condition | Status |
|---|---|---|---|
| unverified Tetherland endpoint/auth/schema creates incorrect pricing or leaks credentials | Tetherland adapter has no HTTP client/URL and always fails closed; tentative `/currencies` path removed; default priority excludes Tetherland | later dedicated task must verify exact official endpoint, auth requirement and schema before enabling network access | Open / fail-closed |
| provider contract drift changes Nobitex interpretation | fixed official `GET /market/stats?srcCurrency=usdt&dstCurrency=rls`; strict `status=ok`, `stats.usdt-rls`, `bestSell/bestBuy/latest` shape; malformed payload fails closed | re-verify official contract before future adapter changes and monitor operational health later | Controlled for current contract / operational drift remains |
| provider URL becomes SSRF surface | endpoint is compile-time fixed HTTPS constant; constructor accepts no URL/endpoint | future providers must retain allowlisted fixed destination design | Controlled |
| TLS or redirect weakens network trust | TLS verification is explicitly enabled; redirects disabled | preserve these options under future HTTP refactors | Controlled |
| oversized/slow provider response exhausts resources | bounded connect/request timeout, content-length guard, body-length guard and JSON-depth/shape validation | deployment networking still requires general monitoring | Controlled at adapter boundary |
| stale/future rate accepted | injected Clock and bounded max-age/future skew validation | upstream data quality within allowed age remains operational risk | Controlled |
| unreasonable rate accepted | fixed-precision min/max sanity bounds before quote creation | configuration values require safe deployment ownership | Controlled by explicit policy |
| disagreeing external sources silently select bad primary | secondary divergence measured with bcmath basis points; over-threshold evidence fails closed unless explicit emergency manual policy applies | future source mix requires explicit reviewed policy | Controlled |
| provider repeatedly failing remains hot | circuit breaker records failure and opens for bounded cooldown | distributed/shared health semantics remain later control-plane work | Controlled locally |
| emergency manual rate masks external outage | manual fallback must be explicitly enabled and configured; no implicit manual fallback | operator must manage manual rate freshness/policy; later admin UX may require additional approval | Explicit High operational policy |
| float rounding loses money | all rates/amounts are fixed decimal strings and bcmath; IRR input is integer; margin is integer bps; deterministic round-up precision capped at six | future arithmetic changes require financial regression proof | Controlled |
| destination changes reinterpret historical quote | immutable destination versions and configuration hashes are snapshotted into amount quote | future payment intent must consume stored destination identity, not current config | Controlled |
| private key/signing secret enters config | destination model stores only public address plus non-secret metadata; no key/secret field exists | future signing/verification must use separate secret-management boundary if ever required | Controlled by absence |
| unauthorized destination mutation | execution-time `payments.usdt.manage` authorization before replay/effect; active administrator and DB guards | presentation layer must continue to supply authenticated admin actor | Controlled |
| replay after rate/config changes creates new economics | accepted quote returned by quote key/request hash before live re-resolution; changed input conflicts | downstream payment flow must preserve this immutable quote | Controlled |
| expired source BUY-002 Quote funds new amount quote | new quote calls existing `QuoteService::current()` and fails on expired source; DB guard joins source Quote validity | Order/payment orchestration still later | Controlled |
| forged/mutated amount quote bypasses application checks | FKs, checks, current destination/source joins, deterministic exact-USDT recomputation, snapshot/hash verification and update/delete triggers | privileged direct DB operators remain outside application trust boundary | Controlled within application/database contract |
| USDT quote accidentally causes financial/payment effect | service has no capture/ledger/order behavior; tests assert Payment Intent/Ledger counts unchanged | downstream integration must introduce explicit separate authority | Controlled by absence |
| W-005 PAY-001 conflict | no shared Payments registration/eligibility file is changed; runtime construction remains isolated under `Payments/Usdt` | MASTER reviews current target/diff before integration | Low/Medium integration risk |
| incomplete `USDT-002` is misreported as complete | evidence, traceability, risk and PR explicitly mark Tetherland unavailable and `USDT-002` partial; Issue #34 remains open | later dedicated Tetherland contract task plus acceptance evidence | Controlled documentation / gap open |

## Financial and security observations

1. Rate provenance is immutable source/rate/fetch/hash evidence, not raw provider payload storage.
2. Nobitex is the only runnable external HTTP provider in Revision 2; its URL cannot be caller-controlled.
3. Tetherland performs zero network I/O until a separate verified contract exists.
4. Manual emergency fallback is an explicit policy decision, never a silent recovery path.
5. Integer IRR, bcmath rates and deterministic round-up avoid monetary float.
6. Destination configuration contains only a public BEP20 address; no signing/private-key material is introduced.
7. Accepted amount quotes are append-only historical facts and are not refreshed when rates/destination configuration change.
8. No Payment Intent, capture, Order, Wallet/Ledger, provider mutation, provisioning or Service effect exists in this increment.

## Evidence-backed risk proof

Implementation CI `31313380720` / `#1358` passed all five mandatory jobs. Full suite: **435 / 2724**. `UsdtRateProviderContractTest`: **7 / 44**. `UsdtRateQuoteFoundationTest`: **3 / 57**. Combined W-007 focused: **10 / 101**. Existing BUY-002 `QuotePricingSnapshotTest`: **8 / 63**.

Artifact `test-evidence-31313380720`, ID `9038041663`, size `128922` bytes. Independently downloaded SHA-256 `8c786a0226153c8c9edd2e6fa85721b51b4b9772e8e977bc4b79447a86427953`, exactly matching the uploader digest.

## Merge-risk note

This Worker evidence is not merge approval. Financial/network/authorization risk remains High. Independent MASTER review and explicit owner approval remain required before merge. PR `#37` stays Draft and W-007 does not merge it.

## Explicit residual non-acceptance

The following remain unaccepted after Contract Revision 2:

- runnable Tetherland integration and complete `USDT-002`;
- `USDT-003` TXID/chain verification/capture;
- Payment Intent or purchase capture;
- Orders;
- Wallet/Ledger effects or refunds;
- `PAY-001` / W-005 routing and eligibility;
- Zarinpal/NOWPayments/C2C/Gift Card;
- provider-live credentials/workflows;
- provisioning/Services or Telegram purchase UX;
- Phase `0.5.0` closure or production release readiness.
