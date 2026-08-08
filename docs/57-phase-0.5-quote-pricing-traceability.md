# Phase 0.5 Deterministic Pricing / Immutable Quote Traceability

**Status:** parallel-verified / accepted.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirement:** `BUY-002`.  
**Implementation head:** `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`.  
**Implementation CI:** `31267071664` / `#1233` — success, 392 tests / 2355 assertions.  
**Evidence head:** `07840d417e64eb30bf31f17bb9e26c1fe549eef3`.  
**Evidence CI:** `31267346707` / `#1236` — success, 392 tests / 2355 assertions.  
**Evidence:** `evidence/0.5.0/quote-pricing-snapshot.md`.

## Requirement-to-proof map

| Requirement / invariant | Accepted proof | Remaining scope |
|---|---|---|
| immutable Quote identity | unique caller `quote_key`, canonical request hash, ULID public ID, append-only DB guards | later Order must bind a Quote without rewriting it |
| base price snapshot | current `plan_offerings.base_price_irr` plus exact Offering ID/code/version/configuration hash | purchase eligibility remains `BUY-001` |
| account/tier/agent override snapshot | explicit `override_source`, reference and integer-IRR price; current tier/agent references are revalidated | actual stored pricing-rule resolution and `AGT-005` remain later |
| discount snapshot | explicit discount reference/value, Offering discount eligibility checked, zero discount represented explicitly | `PRO-001` qualification/reservation/redemption/release remains later |
| deterministic final amount | effective price = override or base; final = effective - discount; DB independently checks arithmetic | payment-method exact adjustment remains later `PAY-001` work |
| `IRR` only | currency fixed to `IRR`; amounts use integer/bigint and non-negative checks | non-IRR pricing is outside the current authoritative product boundary |
| validity snapshot | immutable `valid_from` / `expires_at`; current lookup rejects expiry | later Order must reject/replace stale Quote rather than reinterpret it |
| configuration snapshot | bounded canonical JSON + SHA-256 with Offering and pricing components | later pricing engines must add their resolved rule/config identity |
| later config-change stability | accepted key replays the old Quote; a new key snapshots current Offering config | explicit re-quote UX/order flow remains later |
| DB immutability | insert guards plus unconditional update/delete rejection; forged snapshot hash rejected | later Order references must preserve immutable meaning |
| no payment/order side effect | Quote creation touches no ledger/payment/provider/paid-Order/provisioning state | those effects remain their owning requirements |
| `DAT-003` | transactions, locks, unique key, FKs, checks, current Offering/history and reference validation | later promotion/provider concurrency needs feature-specific proof |
| `DAT-004` | accepted Quote is non-updatable/non-deletable and historically interpretable | later Order/receipt history must also remain immutable |
| `QUA-001` | exact implementation and evidence-head CI, retained artifacts, independent SHA-256/JUnit inspection | preserve lifecycle for every later increment |

## Accepted pricing order and bounded authority

The first `BUY-002` boundary stores:

1. Offering base price;
2. optional already-resolved account/tier/agent override;
3. optional already-resolved eligible discount;
4. final integer-IRR amount.

`QuotePricingInput` is a resolved internal pricing-input boundary, not a public customer-controlled discount/override API. The Quote does not invent promotion, referral, agent-pricing, payment-method, purchase, or provider rule resolution.

Draft/hidden Offering state may be snapshotted because this boundary proves historical pricing semantics only. Later `BUY-001` execution must perform fresh authoritative Offering/route/customer eligibility and may reject a historically valid Quote.

## Replay and conflict semantics

The canonical request hash includes subject ID, Offering ID, override source/reference/price, discount reference/value, and exact UTC expiry.

Exact key/request reuse returns the stored Quote. Any changed request component with the same key conflicts. Later mutable Offering configuration does not reinterpret the stored Quote; a new Quote key reads and snapshots the new Offering version/configuration.

## Database controls

Relational checks enforce valid subject/override/currency shapes, non-negative arithmetic, discount bounds, validity order, SHA-256 lengths, and bounded JSON snapshot shape/size.

The insert trigger rechecks active subject/account type, exact current Offering commercial identity plus immutable Offering-history hash, current tier/agent reference where used, discount eligibility, and snapshot hash. Update and delete always fail.

No Quote trigger/service writes wallet, ledger, Payment Intent, provider, paid Order, provisioning, or Service state.

## Dedicated tests

`QuotePricingSnapshotTest` — **8 tests / 63 assertions**:

1. base price/currency/config snapshot + no financial effect + exact replay;
2. changed same-key pricing/validity conflict;
3. override-before-discount and discount-over-effective rejection;
4. discount eligibility and malformed/negative input rejection;
5. current tier/agent reference validation and stale-reference rejection;
6. historical stability across later Offering version/price/configuration change;
7. expiration rejection plus historical exact replay;
8. DB update/delete and forged-snapshot rejection.

## Exact implementation verification

Implementation head `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`:

- CI `31267071664` / `#1233` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**;
- dedicated Quote suite **8 / 63**;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- uploader and independent SHA-256 `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

## Exact evidence-head verification

Evidence head `07840d417e64eb30bf31f17bb9e26c1fe549eef3`:

- CI `31267346707` / `#1236` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**, zero failures/errors/skips;
- dedicated Quote suite **8 / 63**, zero failures/errors/skips;
- artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- uploader and independently recomputed SHA-256 `ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`;
- independent artifact read found exactly JUnit, test log, Clover coverage, compose state, and compose log;
- bounded safe scan found no known CI credential values, bearer/basic authorization values, PasarGuard protected-secret values, or private-key headers.

This satisfies the exact-SHA implementation/evidence lifecycle for the bounded `BUY-002` Quote foundation.

## Risk disposition

`FIN-17` is controlled for the accepted Quote snapshot boundary: later Offering/configuration mutation, changed explicit pricing input, or expiry cannot silently reinterpret an accepted Quote.

This acceptance does not reduce risks owned by promotion/referral qualification/redemption, most-specific agent pricing, payment-method adjustment, purchase eligibility, Order payment transitions, provider settlement, or provisioning. Those remain explicit later gates.

## Explicit non-claims

No claim is made for:

- `BUY-001` purchase eligibility/Order state;
- complete `PRO-001` promotion lifecycle;
- `REF-001` referral reward lifecycle;
- `AGT-005` most-specific agent pricing resolution;
- stored account/tier/agent override-policy computation beyond resolved-input snapshot/reference validation;
- `PAY-001` payment-method eligibility/exact adjustment;
- payment provider/native refund execution;
- wallet debit/payment capture;
- provisioning/Service lifecycle;
- Phase `0.4.0`, Phase `0.5.0`, or final release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment-specific acceptance remains mandatory at final release acceptance.

## Continuation

Next independent Phase `0.5.0` work should begin with a bounded stored promotion/referral/pricing-rule resolution foundation (`PRO-001` / `REF-001` scope), then move to most-specific `AGT-005` pricing and `PAY-001`/provider work. Do not pull Phase `0.6.0` Order/provisioning execution forward. Current handoff: `docs/52-current-continuation-handoff.md`.
