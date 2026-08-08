# Phase 0.5 Deterministic Pricing / Immutable Quote Traceability

**Status:** implementation-verified evidence candidate; exact evidence-head CI remains required before acceptance.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirement:** `BUY-002`.  
**Implementation head:** `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`.  
**Implementation CI:** `31267071664` / `#1233` — success, 392 tests / 2355 assertions.  
**Evidence:** `evidence/0.5.0/quote-pricing-snapshot.md`.

## Requirement-to-proof map

| Requirement / invariant | Implementation proof | Remaining scope |
|---|---|---|
| immutable Quote identity | unique caller `quote_key`, canonical request-payload hash, ULID public ID, append-only DB guards | later Order must bind a Quote without rewriting it |
| base price snapshot | current `plan_offerings.base_price_irr` plus exact Offering ID/code/version/configuration hash | purchase eligibility remains `BUY-001` |
| account/tier/agent override snapshot | explicit `override_source`, reference and integer-IRR price; tier/agent references are revalidated against current stored subject state | actual account/tier/agent pricing-rule resolution remains later; `AGT-005` not claimed |
| discount snapshot | explicit discount reference/value, Offering discount eligibility checked, zero discount represented explicitly | promotion/referral qualification/redemption remains later |
| deterministic final amount | effective price = override or base; final = effective - discount; DB independently checks arithmetic | payment-method exact adjustment remains `PAY-001` work |
| `IRR` only | quote currency fixed to `IRR`; every amount integer/bigint and non-negative | non-IRR pricing is outside current authoritative product boundary |
| validity snapshot | immutable `valid_from` / `expires_at`; current lookup rejects expiry | later Order must explicitly reject/replace stale Quote rather than reinterpret it |
| configuration snapshot | bounded canonical JSON + SHA-256; includes Offering version/hash/state/visibility, pricing components and formula version | later pricing-rule engines must add their own resolved rule/config identity |
| later config-change stability | request hash is caller-request identity, not mutable Offering state; same accepted key replays old Quote while a new key snapshots current Offering config | explicit re-quote UX/order flow remains later |
| DB immutability | insert guards plus update/delete rejection; forged snapshot hash rejected | later Order references must preserve FK/immutable meaning |
| no payment/order side effect | Quote creation touches no ledger/payment tables and does not create/mark a paid Order | Order/payment/provisioning remain later ownership |
| `DAT-003` | transaction, row locks, unique key, FKs, checks, current Offering/history and tier/agent reference validation | later promotion/provider concurrency needs feature-specific proof |
| `DAT-004` | accepted Quote is non-deletable/non-updatable and remains historically interpretable | later Order/receipt history must also be immutable |
| `QUA-001` | exact implementation-head full CI, retained artifact and independent SHA-256/JUnit inspection | exact evidence-head CI/artifact still mandatory |

## Pricing order and bounded authority

This first Quote boundary represents the authoritative pricing order as stored components:

1. Offering base price;
2. an optional already-resolved account/tier/agent override;
3. an optional already-resolved eligible discount;
4. final integer-IRR amount.

It does not invent promotion, referral, agent-pricing or payment-method rule resolution. Those later engines must produce explicit resolved inputs/configuration identities that are snapshotted before they can affect a Quote.

`QuotePricingInput` therefore acts as a resolved internal pricing-input boundary, not a public customer-controlled discount/override API. Current tier/agent references are revalidated at Quote creation. The generic account override source is snapshotted but its policy/resolution is intentionally unclaimed until stored account-pricing rules exist.

## Offering snapshot semantics

The service locks the current Offering and reads its matching `plan_offering_histories.to_configuration_hash`. Quote storage carries the Offering ID, code, version, base price, discount eligibility and configuration hash plus a bounded configuration snapshot.

Draft/hidden Offering state can be snapshotted because `BUY-002` defines price snapshot semantics; this boundary does not claim that a Quote is purchasable. Later `BUY-001` Order creation/execution must perform fresh authoritative Offering/route/customer eligibility checks and may reject a Quote even though its historical price snapshot is valid.

This avoids a false coupling where pricing evidence would silently activate Phase 0.4 Targets or preempt Phase 0.6 Order/provisioning gates.

## Replay and conflict semantics

The canonical Quote request hash includes:

- subject ID;
- Offering ID;
- override source/reference/price;
- discount reference/value;
- exact UTC expiry.

Exact key/request reuse returns the stored Quote. Any changed request component with the same key conflicts.

Mutable Offering configuration is intentionally not part of the replay request hash. If the Offering later changes, exact accepted key/request replay still returns the immutable historical Quote. A new Quote key reads and snapshots the new Offering version/configuration. Tests verify old base price/hash remain unchanged and a new Quote uses the newer base/version/hash.

## Database controls

`quotes` relational checks enforce valid subject/override/currency shapes, non-negative arithmetic, discount bounds, validity order, SHA-256 lengths and bounded JSON snapshot shape/size.

The insert trigger rechecks active subject/account type, exact current Offering commercial identity plus immutable Offering-history hash, current tier/agent reference where used, discount eligibility and the snapshot hash. Update and delete always fail.

No Quote trigger/service writes wallet, ledger, Payment Intent, provider, paid Order, provisioning or Service state.

## Dedicated tests

`QuotePricingSnapshotTest` — **8 tests / 63 assertions**:

1. base price/currency/config snapshot + no financial effect + exact replay;
2. changed same-key pricing/validity conflict;
3. override-before-discount and discount-over-effective rejection;
4. discount eligibility and malformed/negative input rejection;
5. current tier/agent reference validation and stale-ref rejection;
6. historical stability across later Offering version/price/configuration change;
7. expiration rejection plus historical exact replay;
8. DB update/delete and forged-snapshot rejection.

## Exact implementation verification

Implementation head `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`:

- CI `31267071664` / `#1233` — all mandatory jobs success;
- full MariaDB/authenticated Redis suite **392 tests / 2355 assertions**, zero failures/errors/skips;
- dedicated Quote suite **8 / 63**, zero failures/errors/skips;
- `Pint` clean, PHPStan no errors, forbidden-pattern and architecture checks clean;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- GitHub uploader and independent SHA-256 `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`;
- artifact contains exactly JUnit, full test log, Clover coverage and two dependency-service evidence files;
- independent safe scan found no known CI credential values, bearer/basic authorization values or private-key headers.

## Risk disposition candidate

Pending exact evidence-head acceptance, this implementation directly reduces the pricing-snapshot risk that later mutable Offering configuration, changed explicit override/discount inputs or expiration could silently reinterpret an accepted Quote.

It does not yet reduce risks owned by promotion/referral qualification, most-specific agent pricing, payment-method adjustment, purchase eligibility, Order payment transitions or provider settlement. Those remain explicit later gates rather than being hidden behind the Quote snapshot.

## Explicit non-claims

No claim is made for:

- `BUY-001` purchase eligibility/Order state;
- promotion/referral engine or redemption;
- `AGT-005` most-specific agent pricing resolution;
- stored account/tier/agent override-policy computation beyond current reference validation and resolved-input snapshot;
- `PAY-001` payment-method eligibility/exact adjustment;
- payment provider/native refund execution;
- wallet debit/payment capture;
- provisioning/Service lifecycle;
- Phase `0.4.0`, Phase `0.5.0` or final release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment-specific acceptance remains mandatory at final release acceptance.

After exact evidence-head acceptance, continue with stored promotion/referral/pricing-rule resolution and agent pricing, then payment-method/provider work.
