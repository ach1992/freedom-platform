# Phase 0.5 Promotion Usage Reservation / Release Traceability

**Status:** implementation-verified Worker evidence candidate; evidence-head CI and MASTER review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `PRO-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `DAT-004`, `SEC-001`, `SEC-002`, `QUA-001`.  
**Implementation head:** `1f28553e36e23eda9c1a3f9270f135967af200b8`.  
**Implementation CI:** `31288770144` / `#1281` — all mandatory jobs successful, **413 tests / 2489 assertions**.  
**Evidence:** `evidence/0.5.0/promotion-usage-reservation-capacity.md`.

## Requirement-to-proof map

| Requirement / invariant | Worker proof | Deliberately deferred |
|---|---|---|
| bounded `PRO-001` reservation | `PromotionUsageReservationService::reserve()` accepts only a persisted matched positive-discount `promotion` resolution and persists one immutable usage reservation | successful-payment redeem/finalize remains deferred |
| immutable historical promotion identity | reservation binds stored rule ID/code/version/configuration hash plus resolution snapshot hash; no mutable re-resolution occurs | later redemption must consume this identity unchanged |
| legitimate pricing subject | `PromotionUsageContext` actor must equal resolution/reservation user before replay/effect; new reserve revalidates active `customer|agent` state | no administrator identity is fabricated; later presentation integration must supply authenticated subject |
| `BUY-002` read-only binding | existing Quote is checked for subject, Offering, effective price, discount reference/value, IRR, snapshot hash and validity window | Quote persistence/service semantics remain unchanged; no Order authority is inferred |
| free-order fail-closed | zero-final-price Quote requires historical `allows_free_order` input | no free-order Order/payment execution is introduced |
| global usage limit | exact rule-version row is locked and committed active reservations are counted before insert | redeemed/permanent consumption does not yet exist |
| per-user usage limit | same rule-version serialization plus active reservation rows filtered by user | no caller-provided remaining-capacity input is trusted |
| released capacity reusable | capacity authority excludes reservations having an immutable release row; functional and subprocess race tests prove reuse | automatic release orchestration is deferred |
| active reservation consumes capacity | unreleased stored reservation remains in authoritative count regardless of later caller-observed counters | no background expiry/payment integration is claimed |
| reserve replay/conflict | unique reservation key + request payload hash; exact replay returns same row, changed key reuse conflicts | future redemption needs its own idempotency identity |
| release replay/conflict | unique release key, unique one-release-per-reservation and payload hash; exact replay returns same release; second terminal release fails | no redemption terminal state exists in Revision 2 |
| cross-user isolation | actor check precedes reserve/release replay/effect | HTTP/Telegram routing remains future integration |
| no-match/referral rejection | matched positive discount and `rule_kind_snapshot = promotion` required | referral reward lifecycle remains separate |
| forged identity rejection | application revalidates stored snapshot hashes; DB insert guards rejoin resolution/rule/version/Quote and enforce exact identity | no external rule adapter is introduced |
| rule mutation stability | later disabled version does not change exact historical reservation replay | no mutable reinterpretation at future payment phase is allowed |
| `DAT-002` | all discount/Quote amounts remain integer IRR; no monetary float | crypto/provider precision belongs to other tasks |
| `DAT-003` | MariaDB transaction, deterministic rule-version lock, FKs, unique constraints, checks, triggers and independent-process contention tests | future redeem/payment races require separate proof |
| `DAT-004` | reservation and release records are append-only; update/delete triggers reject rewriting history | future redemption must add immutable history rather than edit these rows |
| `SEC-001` | execution-time subject authorization before replay/effect; active subject required for new reserve | no admin/provider authority expansion |
| `SEC-002` | no secrets/provider payloads introduced; secret scan passes | provider credentials remain out of scope |
| `QUA-001` | exact-head five-job CI, full suite, focused suites, real-MariaDB subprocess proof and retained artifact/digest | evidence head still requires its own exact-head five-job CI |

## Storage ownership

Revision 2 adds two Promotions-owned tables only:

1. `promotion_usage_reservations` — immutable accepted reservation identity and historical policy/context snapshot;
2. `promotion_usage_releases` — immutable explicit terminal release identity, one per reservation.

The migration is forward-only: `database/migrations/2026_08_09_003100_create_promotion_usage_reservation_foundation.php`. No integrated migration is edited.

A reservation uniquely claims one accepted promotion resolution and one accepted Quote. This prevents one immutable pricing decision or Quote from funding multiple concurrent promotion reservations under different idempotency keys.

## Locking and capacity authority

`pricing_rule_versions` is immutable and therefore a stable serialization row. Reserve locks the exact historical version before reading current active reservation state; release locks that same version before adding its immutable release row. Consequently reserve/reserve and reserve/release operations for one capacity pool serialize on the same database row.

Capacity is calculated from committed `promotion_usage_reservations` left-joined to `promotion_usage_releases`, where no release exists. The resolution fields `observed_total_uses` and `observed_user_uses` remain historical inputs to the earlier #25 resolver only and have no authority here.

No isolation level is weakened. Lock wait is bounded only in subprocess tests so a deadlock/stall fails visibly rather than hanging CI.

## Read-only Quote contract

The existing Quote boundary contains enough immutable data for safe reservation binding without any Quote schema or service modification. Reservation requires:

- same `user_id` as the promotion resolution;
- same `plan_offering_id`;
- discount-eligible Offering snapshot;
- Quote `effective_price_irr` equal to resolution `input_price_irr`;
- Quote discount reference equal to the immutable promotion rule code;
- exact discount amount match;
- `IRR` currency;
- valid `valid_from` / `expires_at` window at reservation time;
- valid Quote configuration snapshot hash.

This binding proves pricing-context consistency only. It is **not** Order or purchase-payment authority.

## Test traceability

Implementation CI `31288770144` / `#1281`:

- full repository suite: **413 tests / 2489 assertions**;
- `PromotionUsageReservationFoundationTest`: **10 / 44**;
- `PromotionUsageReservationContentionVerificationTest`: **4 / 16**;
- focused reservation total: **14 / 60**;
- existing `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- existing `QuotePricingSnapshotTest`: **8 / 63**;
- zero failures/errors/skips in the listed suites.

Contention scenarios use independent PHP processes against real MariaDB and a `READY` / `GO` barrier:

- final global slot;
- final per-user slot;
- duplicate exact reserve;
- reserve-vs-release final-slot serialization and capacity reuse.

## Evidence identity

Implementation artifact `test-evidence-31288770144`, ID `9030743601`, size `122261` bytes, retained 30 days. Independent SHA-256: `a93d4e979b25a30aa49c8073a53550aa214c24800a3151c36e0a91ea4f43fd4d`, matching the uploader digest exactly.

## Explicit non-claims

Revision 2 does not claim:

- payment-success promotion redemption/finalization;
- automatic Payment Intent failed/expired/canceled release integration;
- complete `PRO-001`;
- generic/purchase Payment Intents or purchase-bound payment authority;
- `PAY-001`;
- Orders;
- Wallet/ledger/payment/provider effects;
- provider behavior/live compatibility;
- referral rewards, `PRO-002`, `AGT-005`;
- provisioning/Services or Telegram purchase UX;
- Phase `0.5.0` closure or release acceptance.

The accepted `wallet_top_up` Payment Intent is deliberately never treated as purchase-payment authority.
