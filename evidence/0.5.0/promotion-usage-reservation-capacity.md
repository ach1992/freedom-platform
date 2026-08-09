# Phase 0.5 Promotion Usage Reservation / Release Capacity Evidence

**Status:** correction implementation-verified Worker evidence candidate; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `PRO-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `DAT-004`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `8a501c149614533d93bec3d69a19cc3e584db2d7`.  
**Previous reviewed head:** `8801b317c1fc2102b62e6f5b0c6aec26496fa94d`.  
**Corrected implementation head:** `64dd7b52dd07395ca0d39924cb5812e41b2ec945`.  
**Implementation CI:** `31292316629` / `#1299` — all five mandatory jobs successful.  
**Full suite:** **417 tests / 2509 assertions**.  
**Focused reservation suites:** **18 tests / 80 assertions**.  
**Promotion usage contention suites:** **5 tests / 22 assertions**.  
**Cross-version correction proof:** **4 tests / 20 assertions**, including **1 / 6** independent-process contention.  
**Implementation artifact:** `test-evidence-31292316629`, ID `9031813877`, size `122526` bytes.  
**Independent artifact SHA-256:** `0a50795f2f9bf0ddf97ab72b1d91f3ccbc4ceb31ad52946116a0631fdaa04fa6`.

## Bounded outcome

Contract Revision 2 intentionally implements only authoritative promotion usage reservation/capacity plus explicit release. It does **not** implement successful-payment redemption/finalization or automatic Payment Intent driven release.

A reservation can be created only from an already persisted, immutable, matched, positive-discount `promotion` resolution. The service does not run mutable promotion selection again. It revalidates the resolution's stored stable rule ID, rule code, rule version, rule configuration hash and configuration snapshot hash against the immutable rule/version rows.

The accepted `BUY-002` Quote is consumed read-only. Reservation requires the Quote to match the resolution subject, Offering, effective pre-discount price, rule-code discount reference, discount amount and IRR currency, and the Quote must still be inside its immutable validity window. The Quote snapshot hash is verified. A zero-final-price Quote remains fail-closed unless the historical promotion rule version explicitly allowed free-order policy input; no free-order execution is created by this increment.

## Corrected authoritative capacity model

The implementation introduces append-only `promotion_usage_reservations` and `promotion_usage_releases` records. Active capacity consumption is derived from reservations that do not have a release record. No mutable usage counter exists and caller-supplied `observed_total_uses` / `observed_user_uses` fields from rule resolution are never used as capacity authority.

The stable `pricing_rule_id` is the capacity domain. A new reservation still reads `total_use_limit` and `per_user_use_limit` from its matched immutable `pricing_rule_version_id`, preserving version-specific policy provenance, but the capacity calculation counts **all unreleased reservations with the same stable `pricing_rule_id` across every historical version**. A rule revision or disable therefore cannot silently reset global or per-user usage.

Effectful reserve and release operations for a stable promotion rule share one database serialization barrier: the corresponding `pricing_rules` row is locked before the matched/historical `pricing_rule_versions` row and before capacity-changing reservation/release work. This is also the stable identity row used by promotion-rule revision. The corrected lock boundary therefore serializes reserve/reserve and reserve/release operations even when their immutable resolutions refer to different rule versions.

Released reservations cease consuming stable-rule capacity; unreleased reservations continue consuming it. Releasing a version-1 reservation can therefore make capacity available to a version-2 reservation when the version-2 limit policy permits it.

Reservation identity is unique by reservation key, resolution and Quote. Release identity is unique by release key and reservation. Exact replay returns the accepted immutable result. Materially changed key reuse fails closed. A second release under a different key is an invalid terminal transition.

## Authorization and historical identity

The actor user must equal the pricing subject before reservation replay/effect and before release replay/effect. New reservations revalidate that the subject remains an active `customer` or `agent`. Release remains available to the owning subject even if account status later changes so capacity can be explicitly relinquished without fabricating administrator authority.

The reservation snapshots rule/version/configuration identity, resolution/Quote snapshot hashes, subject, Offering, discount, configured limits and free-order permission. A later rule revision or disable cannot reinterpret an existing reservation or exact replay. Stable-rule capacity accounting across versions does not alter the historical rule-version identity stored on each reservation.

## Database integrity

The forward migration `2026_08_09_003100_create_promotion_usage_reservation_foundation.php` adds only new Promotions lifecycle tables and does not edit any integrated migration.

MariaDB controls include:

- FKs to immutable resolution, Quote, user, Offering, stable rule and rule-version identities;
- unique reservation key, release key, resolution claim, Quote claim and one release per reservation;
- positive-discount/version and valid-limit checks;
- SHA-256 format checks and bounded JSON snapshot checks;
- insert guards for active subject, exact promotion resolution/rule/version identity, historical limit/free-order snapshots and immutable Quote binding;
- reservation/release update and delete guards;
- release insert guard binding the release actor and reservation snapshot identity;
- transaction-level `FOR UPDATE` serialization on the stable `pricing_rules` identity row for effectful reserve/release capacity operations.

No Wallet, ledger, provider, Payment Intent, Order, provisioning or Service effect is introduced.

## Independent-process contention proof

`PromotionUsageReservationContentionVerificationTest` and `PromotionUsageReservationCrossVersionContentionVerificationTest` start independent PHP processes against real CI MariaDB, set a bounded InnoDB lock wait, use deterministic `READY` / `GO` barriers, and have bounded process-output watchdogs. Sleep timing is not used as the correctness mechanism.

Verified scenarios:

1. two users competing for the final global slot cannot both reserve;
2. two reservations for one user competing for the final per-user slot cannot both reserve;
3. concurrent exact duplicate reserve creates one reservation and one replay returning the same reservation identity;
4. reserve-vs-release on the final global slot serializes without oversubscription, and released capacity is reusable;
5. **two different immutable versions/resolutions of the same stable promotion rule competing for the final slot cannot both reserve**.

Combined promotion usage contention result on implementation CI `#1299`: **5 tests / 22 assertions**, zero failures/errors/skips. The new cross-version contention test alone is **1 / 6**.

## Cross-version functional correction proof

`PromotionUsageReservationCrossVersionCapacityTest`: **3 tests / 14 assertions** proving:

1. an active version-1 reservation consumes the final global slot after the same stable rule is revised to version 2;
2. an active version-1 reservation consumes version-2 per-user capacity for the same user;
3. releasing the older-version reservation returns stable-rule capacity so a later version-2 reservation can succeed when the current version policy permits it.

Together with the cross-version contention test, the correction-specific suite is **4 tests / 20 assertions**.

## Focused functional and regression proof

`PromotionUsageReservationFoundationTest`: **10 tests / 44 assertions** covering reserve/replay/conflict, release/replay/terminal conflict, authoritative stored capacity, cross-user denial, inactive-subject denial, no-match/referral rejection, Quote mismatch/expiry rejection, historical rule mutation stability, release-key conflict and MariaDB immutability/forgery guards.

Focused reservation total across foundation, existing contention and new cross-version suites: **18 / 80**.

Existing regressions on the same implementation run:

- `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- `QuotePricingSnapshotTest`: **8 / 63**.

The full MariaDB/authenticated-Redis suite passed **417 / 2509**. Repository preflight, Secret scan, Dependency/license policy, PHP static quality and MariaDB/Redis tests all succeeded on the exact corrected implementation head.

The retained artifact contains exactly five evidence files: JUnit XML, full test log, Clover coverage, Docker compose state and sanitized dependency-service log. The independently downloaded ZIP digest matches the uploader digest exactly.

## Explicit deferred/unaccepted behavior

This evidence does **not** claim acceptance of:

- promotion payment-success redemption/finalization;
- any successful-payment consumption transition;
- Payment Intent driven automatic release for failed/expired/canceled paths;
- complete `PRO-001`;
- generic or purchase-bound Payment Intent authority;
- `PAY-001` payment-method eligibility;
- Orders or Order creation;
- Wallet/ledger effects;
- provider transports, provider callbacks or live provider behavior;
- provisioning or Services;
- referral reward lifecycle;
- `PRO-002`;
- `AGT-005`;
- Telegram purchase UX;
- Phase `0.5.0` closure or release acceptance.

The architectural blocker from Revision 1 remains deliberately respected: accepted `wallet_top_up` capture is never treated as purchase-payment authority.

## Evidence-head lifecycle

This document records the exact corrected implementation boundary. The evidence/traceability/risk documentation commit must itself receive all five mandatory CI jobs before Worker handoff. Evidence-head CI/artifact metadata is recorded durably on Issue `#27` and PR `#30` after that run succeeds; this document does not self-assert a future CI outcome.
