# Phase 0.5 Promotion Usage Reservation / Release Risk Note

**Status:** bounded Worker risk disposition; evidence-head CI and MASTER review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Implementation head:** `1f28553e36e23eda9c1a3f9270f135967af200b8`.  
**Implementation CI:** `31288770144` / `#1281` — all five mandatory jobs successful.  
**Evidence:** `evidence/0.5.0/promotion-usage-reservation-capacity.md`.  
**Traceability:** `docs/60-phase-0.5-promotion-usage-reservation-traceability.md`.

## Risk disposition

This increment reduces the high financial/concurrency residual risk previously left open by the promotion-resolution foundation, but only for **reservation capacity and explicit release**. It does not close complete `PRO-001` and does not claim payment-success consumption.

| Risk | Control in Revision 2 | Residual / exit condition | Status |
|---|---|---|---|
| global promotion capacity oversubscribed under concurrent reserve | all operations for one historical rule version lock the same immutable `pricing_rule_versions` row; active stored reservations are counted inside the transaction; final-slot subprocess contention proof | preserve the same historical identity when future redeem/finalize is added | Controlled for reservation / High later financial gate remains |
| per-user capacity oversubscribed | same serialization row plus authoritative active reservations filtered by subject; no caller remaining-capacity input | future permanent redeemed consumption must remain authoritative under contention | Controlled for reservation |
| caller-provided observed counters bypass capacity | `observed_total_uses` and `observed_user_uses` are never read by reservation service | none for this boundary; regress if future code starts trusting them | Controlled |
| duplicate retry creates two reservations | unique reservation key, resolution claim and Quote claim; exact payload replay returns original row; duplicate independent-process proof | future redeem requires separate idempotency/effect identity | Controlled for reservation |
| released capacity remains stuck | release is append-only and active-capacity query excludes released reservations; reuse proven functionally and in reserve-vs-release contention | automatic release orchestration still absent | Controlled for explicit release / operational integration open |
| same reservation released twice or release key reused materially | unique release key, one release per reservation, payload hash and terminal transition check | future redeemed terminal state must become mutually exclusive with release | Controlled for Revision 2 state space |
| cross-user replay leaks or changes capacity | actor subject check occurs before reserve/release replay/effect; new reserve also revalidates active pricing subject | presentation layer must pass authenticated user identity | Controlled at Application Service boundary |
| mutable promotion change reinterprets held reservation | immutable resolution/rule/version/configuration and bounded reservation snapshot; exact replay uses stored history | future payment consumption must use stored reservation identity, never current rule | Controlled for reservation history |
| no-match/referral resolution consumes promotion capacity | reserve requires positive matched `promotion` resolution and DB guard independently verifies kind/identity | referral reward lifecycle is separate | Controlled |
| forged resolution/Quote identity manufactures capacity use | application and DB guards join exact immutable resolution/rule/version/Quote identity and verify hashes/context | direct privileged DB operators remain outside application threat boundary | Controlled within application/database contract |
| expired or incompatible Quote reserves discount | subject/Offering/effective-price/reference/value/currency/hash/window are validated read-only at reserve time | purchase/order orchestration still must consume the same accepted Quote/reservation | Controlled for reservation binding |
| free-order policy silently creates free purchase | zero-final-price Quote requires historical `allows_free_order`; no Order/payment execution is introduced | explicit free-order execution policy remains a later purchase gate | Fail-closed |
| release orchestration never runs for failed/expired/canceled payment | no Payment Intent integration is implemented by design | add only after genuine purchase-bound Payment Intent/Order authority exists | Open / explicitly deferred |
| wallet top-up mistaken for purchase authority | Revision 1 architecture blocker is preserved; service has no Payment dependency and never interprets wallet-top-up capture | genuine purchase-bound payment authority required before redemption | Controlled by absence / later architecture gate |
| successful-payment redemption/finalization absent | explicitly out of scope in Revision 2 | separate Task Contract after purchase-bound payment authority exists, with terminal race/contention proof | Open / High financial gate |
| append-only history rewritten | reservation/release update and delete triggers reject mutation; FKs restrict deletion of dependencies | future redeem must also be append-only | Controlled for this boundary |
| concurrent Worker W-003 conflict | implementation stays in Promotions, a new Promotions migration and dedicated tests/docs; no Agents/agent-pricing files changed | MASTER must evaluate migration order/current target at integration time | Low/Medium integration review |

## Financial safety observations

1. Capacity authority is relational state, not a mutable cached counter.
2. Release compensates capacity by adding history rather than deleting or rewriting a reservation.
3. Monetary values remain integer IRR; no float is introduced.
4. The immutable rule-version row is used as a deterministic per-capacity-pool serialization lock. Isolation is not weakened.
5. Quote binding is pricing-context validation only and cannot be upgraded into payment or Order authority by this module.
6. The reservation lifecycle creates no wallet, ledger, payment, provider, Order, provisioning or Service side effect.

## Evidence-backed risk proof

Implementation CI `31288770144` / `#1281` passed all five mandatory self-hosted jobs. Full suite: **413 tests / 2489 assertions**. Focused reservation suites: **14 / 60**, including independent-process MariaDB contention **4 / 16**. Existing promotion resolution **7 / 74** and Quote **8 / 63** remain green.

Artifact `test-evidence-31288770144`, ID `9030743601`, independently downloaded SHA-256 `a93d4e979b25a30aa49c8073a53550aa214c24800a3151c36e0a91ea4f43fd4d`, matching uploader digest.

## Merge-risk note

This is a high-risk financial/concurrency change. Worker evidence is not merge approval. Independent MASTER review and explicit human approval remain required. MASTER must review the actual current PR head, target compatibility, migration ordering, exact evidence-head CI and protected-scope diff before integration.

## Explicit residual non-acceptance

The following remain unaccepted after this Worker increment:

- successful-payment promotion redemption/finalization;
- automatic release from failed/expired/canceled purchase Payment Intents;
- complete `PRO-001`;
- purchase-bound Payment Intent authority;
- Orders;
- `PAY-001`;
- provider behavior;
- Wallet/ledger effects;
- referral rewards, `PRO-002`, `AGT-005`;
- provisioning/Services;
- Phase `0.5.0` closure or production release readiness.
