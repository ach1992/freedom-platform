# Phase 0.5 USDT BEP20 Rate / Immutable Amount Quote Risk Note

**Status:** bounded Worker Contract Revision 2 risk disposition; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#34`, Worker `W-007`, Contract Revision `2`.  
**Corrected implementation head:** `c8cafbcdb53b69d94080f6ee7d486cff2dc74fe5`.  
**Implementation CI:** `31316814017` / `#1367` — all five mandatory jobs successful, **436 tests / 2735 assertions**.  
**Evidence:** `evidence/0.5.0/usdt-rate-quote-foundation.md`.  
**Traceability:** `docs/phase-0.5-usdt-rate-quote-traceability.md`.

## Risk disposition

This increment is High risk for financial/pricing integrity, external-network security and configuration authorization. Contract Revision 2 narrows the external integration boundary rather than accepting an unverified Tetherland contract. The accepted runnable provider set is manual plus verified Nobitex public market data; Tetherland remains fail-closed/no-network and complete `USDT-002` remains open.

| Risk | Control in Revision 2 | Residual / exit condition | Status |
|---|---|---|---|
| standard install omits the USDT management permission/grant | `DatabaseSeeder` now invokes `UsdtAccessFoundationSeeder` immediately after Identity/Access; standard-seed regression proves permission existence, active finance grant, non-owner finance success and ungranted non-owner denial | preserve dependency ordering and finance-role intent in future seed refactors | Controlled |
| owner bypass masks missing role-based authorization | dedicated regression succeeds through an active non-owner finance assignment and uses the production authorizer; owner bypass is not used for success proof | future permission changes must keep a non-owner authorization regression | Controlled |
| unverified Tetherland endpoint/auth/schema creates incorrect pricing or leaks credentials | Tetherland adapter has no HTTP client/URL and always fails closed; tentative `/currencies` path removed; default priority excludes Tetherland | later dedicated task must verify exact official endpoint, auth requirement and schema before enabling network access | Open / fail-closed |
| provider contract drift changes Nobitex interpretation | current official `apiv2.nobitex.ir` host plus fixed `GET /market/stats?srcCurrency=usdt&dstCurrency=rls`; strict response shape; malformed payload fails closed | re-verify official contract before future adapter changes | Controlled for current contract / operational drift remains |
| provider URL becomes SSRF surface | endpoint is compile-time fixed HTTPS constant; constructor accepts no URL/endpoint | future providers must retain allowlisted fixed destination design | Controlled |
| TLS/redirect or resource bounds weaken network trust | TLS verification enabled, redirects disabled, bounded connect/request/body/depth parsing | preserve under future HTTP refactors | Controlled |
| stale/unreasonable/divergent rate accepted | max-age/future-skew checks, fixed-precision min/max bounds, bcmath divergence, explicit fallback policy | policy configuration remains operationally sensitive | Controlled |
| provider repeatedly failing remains hot | cache-backed circuit breaker opens for bounded cooldown | distributed/shared health semantics remain later | Controlled locally |
| emergency manual rate masks outage | manual fallback must be explicitly enabled/configured; no implicit fallback | operator-owned emergency policy remains High operational risk | Explicit policy |
| float rounding loses money | integer IRR, bcmath decimal strings, integer bps and deterministic round-up capped at six decimals | future arithmetic changes require regression proof | Controlled |
| destination changes reinterpret historical quote | immutable destination versions/configuration hashes are snapshotted | future payment flow must consume stored destination identity | Controlled |
| private key/signing secret enters config | only public BEP20 address/non-secret metadata exists | signing remains separate if ever required | Controlled by absence |
| unauthorized destination mutation | execution-time `payments.usdt.manage`; standard finance grant is provisioned and non-owner path proven; ungranted admin denied | presentation layer must supply authenticated admin actor | Controlled |
| replay after rate/config changes creates new economics | accepted quote returned before live re-resolution; changed input conflicts | downstream payment flow must preserve immutable quote | Controlled |
| expired source BUY-002 Quote funds new amount quote | `QuoteService::current()` and DB joins enforce source validity | Order/payment orchestration remains later | Controlled |
| forged/mutated amount quote bypasses application checks | FKs, checks, joins, exact-USDT recomputation, snapshot/hash verification and immutable triggers | privileged direct DB operators outside app boundary | Controlled |
| USDT quote accidentally causes payment effect | no capture/ledger/order behavior; regressions keep those effects absent | future integration requires separate authority | Controlled by absence |
| W-005 PAY-001 conflict | no shared Payments registration/eligibility surface changed; only the MASTER-required standard seeder chain is shared | MASTER reviews final diff before integration | Low/Medium integration risk |
| incomplete `USDT-002` is misreported as complete | evidence/PR explicitly mark Tetherland unavailable and `USDT-002` partial; Issue #34 remains open | dedicated Tetherland task required | Gap open / documentation controlled |

## Financial and security observations

1. Standard installation now provisions the same `payments.usdt.manage` permission and finance grant that the service expects at execution time.
2. The authorization proof uses a non-owner administrator, so it cannot pass solely because of owner bypass.
3. Nobitex is the only runnable external HTTP provider; its fixed current-host URL cannot be caller-controlled.
4. Tetherland performs zero network I/O until a separate verified contract exists.
5. Manual emergency fallback is explicit, not silent.
6. Integer IRR, bcmath rates and deterministic round-up avoid monetary float.
7. Destination configuration contains only a public BEP20 address; no signing/private-key material is introduced.
8. Accepted amount quotes are append-only historical facts and create no Payment Intent, capture, Order, Wallet/Ledger, provisioning or Service effect.

## Evidence-backed risk proof

Implementation CI `31316814017` / `#1367` passed all five mandatory jobs. Full suite: **436 / 2735**. `UsdtRateProviderContractTest`: **7 / 44**. `UsdtRateQuoteFoundationTest`: **3 / 57**. `UsdtStandardSeedAuthorizationTest`: **1 / 11**. Combined W-007 focused: **11 / 112**. Existing BUY-002 `QuotePricingSnapshotTest`: **8 / 63**.

Artifact `test-evidence-31316814017`, ID `9039007750`, size `129026` bytes. Independently downloaded SHA-256 `b5d8b9ee82dae2e65cc0f6f3998980d45033d227a22d7d3319dbc890806a0b8f`, exactly matching the uploader digest.

## Merge-risk note

This Worker evidence is not merge approval. Financial/network/authorization risk remains High. Independent MASTER review and explicit owner approval remain required before merge. PR `#37` stays Draft and W-007 does not merge it.

## Explicit residual non-acceptance

The following remain unaccepted after Contract Revision 2: runnable Tetherland integration and complete `USDT-002`; `USDT-003` TXID/chain verification/capture; Payment Intent/purchase capture; Orders; Wallet/Ledger effects/refunds; `PAY-001`/W-005 routing and eligibility; Zarinpal/NOWPayments/C2C/Gift Card; provider-live credentials/workflows; provisioning/Services/Telegram purchase UX; Phase `0.5.0` closure or production release readiness.
