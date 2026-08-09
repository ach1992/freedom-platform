# Phase 0.5 Promotion Usage Reservation / Release Traceability

**Status:** correction implementation-verified Worker evidence candidate; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `PRO-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `DAT-004`, `SEC-001`, `SEC-002`, `QUA-001`.  
**Previous reviewed head:** `8801b317c1fc2102b62e6f5b0c6aec26496fa94d`.  
**Corrected implementation head:** `64dd7b52dd07395ca0d39924cb5812e41b2ec945`.  
**Implementation CI:** `31292316629` / `#1299` — all mandatory jobs successful, **417 tests / 2509 assertions**.  
**Evidence:** `evidence/0.5.0/promotion-usage-reservation-capacity.md`.

## Requirement-to-proof map

| Requirement / invariant | Worker proof | Deliberately deferred |
|---|---|---|
| bounded `PRO-001` reservation | `PromotionUsageReservationService::reserve()` accepts only a persisted matched positive-discount `promotion` resolution and persists one immutable usage reservation | successful-payment redeem/finalize remains deferred |
| immutable historical promotion identity | reservation binds stored stable rule ID/code plus immutable version/configuration hash and resolution snapshot hash; no mutable re-resolution occurs | later redemption must consume this identity unchanged |
| stable capacity domain across revisions | effectful reserve locks stable `pricing_rules.id`; active reservations are counted by `promotion_usage_reservations.pricing_rule_id` across all versions | no policy grants a capacity reset on rule revision |
| current matched-version policy | a new reservation still reads global/per-user limits and free-order permission from its matched immutable `pricing_rule_version_id` | future policy changes must not mutate historical reservations |
| legitimate pricing subject | `PromotionUsageContext` actor must equal resolution/reservation user before replay/effect; new reserve revalidates active `customer|agent` state | no administrator identity is fabricated; later presentation integration must supply authenticated subject |
| `BUY-002` read-only binding | existing Quote is checked for subject, Offering, effective price, discount reference/value, IRR, snapshot hash and validity window | Quote persistence/service semantics remain unchanged; no Order authority is inferred |
| free-order fail-closed | zero-final-price Quote requires historical `allows_free_order` input | no free-order Order/payment execution is introduced |
| global usage limit | stable rule row is the serialization barrier; all committed unreleased reservations sharing `pricing_rule_id` count against the matched version's current limit | redeemed/permanent consumption does not yet exist |
| per-user usage limit | same stable-rule serialization plus all unreleased same-rule reservations filtered by user | no caller-provided remaining-capacity input is trusted |
| released capacity reusable | capacity authority excludes reservations having an immutable release row; older-version release can free capacity for a later-version reservation | automatic release orchestration is deferred |
| active reservation consumes capacity | unreleased stored reservation remains in authoritative same-rule count across later revisions regardless of caller-observed counters | no background expiry/payment integration is claimed |
| reserve/release cross-version serialization | reserve and effectful release lock the same stable `pricing_rules` row before version/reservation capacity work | exact no-effect replay can return from immutable history without fabricating new capacity work |
| reserve replay/conflict | unique reservation key + request payload hash; exact replay returns same row, changed key reuse conflicts | future redemption needs its own idempotency identity |
| release replay/conflict | unique release key, unique one-release-per-reservation and payload hash; exact replay returns same release; second terminal release fails | no redemption terminal state exists in Revision 2 |
| cross-user isolation | actor check precedes reserve/release replay/effect | HTTP/Telegram routing remains future integration |
| no-match/referral rejection | matched positive discount and `rule_kind_snapshot = promotion` required | referral reward lifecycle remains separate |
| forged identity rejection | application revalidates stored snapshot hashes; DB insert guards rejoin resolution/rule/version/Quote and enforce exact identity | no external rule adapter is introduced |
| rule mutation stability | historical reservation keeps its immutable version/configuration provenance while stable-rule capacity continues across revision/disable | no mutable reinterpretation at future payment phase is allowed |
| `DAT-002` | all discount/Quote amounts remain integer IRR; no monetary float | crypto/provider precision belongs to other tasks |
| `DAT-003` | MariaDB transaction, deterministic stable-rule then version locking, FKs, unique constraints, checks, triggers and independent-process contention tests | future redeem/payment races require separate proof |
| `DAT-004` | reservation and release records are append-only; update/delete triggers reject rewriting history | future redemption must add immutable history rather than edit these rows |
| `SEC-001` | execution-time subject authorization before replay/effect; active subject required for new reserve | no admin/provider authority expansion |
| `SEC-002` | no secrets/provider payloads introduced; secret scan passes | provider credentials remain out of scope |
| `QUA-001` | exact-head five-job CI, full suite, focused suites, real-MariaDB subprocess proof and retained artifact/digest | evidence head still requires its own exact-head five-job CI |

## Storage ownership

Revision 2 adds two Promotions-owned tables only:

1. `promotion_usage_reservations` — immutable accepted reservation identity and historical policy/context snapshot, including both stable `pricing_rule_id` and immutable `pricing_rule_version_id`;
2. `promotion_usage_releases` — immutable explicit terminal release identity, one per reservation.

The migration is forward-only: `database/migrations/2026_08_09_003100_create_promotion_usage_reservation_foundation.php`. No integrated migration is edited.

A reservation uniquely claims one accepted promotion resolution and one accepted Quote. This prevents one immutable pricing decision or Quote from funding multiple concurrent promotion reservations under different idempotency keys.

## Locking and capacity authority

The corrected capacity domain is the stable `pricing_rules` identity, not an individual immutable version. Reserve derives `pricing_rule_id` from the accepted immutable resolution, locks that stable rule row `FOR UPDATE`, then locks/loads the matched immutable `pricing_rule_versions` row. Effectful release derives the stable rule ID from the immutable reservation, locks the same stable rule row, then validates/locks the historical version before appending the release.

The capacity calculation reads committed `promotion_usage_reservations` left-joined to `promotion_usage_releases`, filters by stable `reservation.pricing_rule_id`, and includes only rows with no release. The matched rule version still supplies the new reservation's `total_use_limit` and `per_user_use_limit`. Therefore version 1 and version 2 of the same stable rule share usage history without losing version-specific configuration provenance.

Promotion rule revision already serializes on the same stable rule identity. The corrected reservation boundary therefore does not create a separate version-level capacity domain that can be reset merely by creating a new version.

The resolution fields `observed_total_uses` and `observed_user_uses` remain historical inputs to the earlier #25 resolver only and have no authority here. No isolation level is weakened. Lock wait is bounded only in subprocess tests so a deadlock/stall fails visibly rather than hanging CI.

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

Corrected implementation CI `31292316629` / `#1299`:

- full repository suite: **417 tests / 2509 assertions**;
- `PromotionUsageReservationFoundationTest`: **10 / 44**;
- `PromotionUsageReservationContentionVerificationTest`: **4 / 16**;
- `PromotionUsageReservationCrossVersionCapacityTest`: **3 / 14**;
- `PromotionUsageReservationCrossVersionContentionVerificationTest`: **1 / 6**;
- focused reservation total: **18 / 80**;
- combined promotion usage contention: **5 / 22**;
- correction-specific cross-version proof: **4 / 20**;
- existing `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- existing `QuotePricingSnapshotTest`: **8 / 63**;
- zero failures/errors/skips in the listed suites.

Contention scenarios use independent PHP processes against real MariaDB and `READY` / `GO` barriers:

- final global slot;
- final per-user slot;
- duplicate exact reserve;
- reserve-vs-release final-slot serialization and capacity reuse;
- **different immutable versions/resolutions of the same stable rule competing for the final slot**.

Cross-version functional proof additionally verifies that a version-1 reservation consumes global and per-user capacity after revision to version 2, and that releasing the older-version reservation returns capacity to version 2 when its policy permits.

## Evidence identity

Implementation artifact `test-evidence-31292316629`, ID `9031813877`, size `122526` bytes, retained 30 days. Independent SHA-256: `0a50795f2f9bf0ddf97ab72b1d91f3ccbc4ceb31ad52946116a0631fdaa04fa6`, matching the uploader digest exactly.

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
