# Phase 0.5 Promotion Usage Reservation / Release Capacity Evidence

**Status:** implementation-verified Worker evidence candidate; evidence-head CI and MASTER review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** bounded `PRO-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `DAT-004`, `SEC-001`, `SEC-002`, `QUA-001`.  
**BASE_SHA:** `8a501c149614533d93bec3d69a19cc3e584db2d7`.  
**Implementation head:** `1f28553e36e23eda9c1a3f9270f135967af200b8`.  
**Implementation CI:** `31288770144` / `#1281` — all five mandatory jobs successful.  
**Full suite:** **413 tests / 2489 assertions**.  
**Focused reservation suites:** **14 tests / 60 assertions**.  
**Independent-process contention suite:** **4 tests / 16 assertions**.  
**Implementation artifact:** `test-evidence-31288770144`, ID `9030743601`, size `122261` bytes.  
**Independent artifact SHA-256:** `a93d4e979b25a30aa49c8073a53550aa214c24800a3151c36e0a91ea4f43fd4d`.

## Bounded outcome

Contract Revision 2 intentionally implements only authoritative promotion usage reservation/capacity plus explicit release. It does **not** implement successful-payment redemption/finalization or automatic Payment Intent driven release.

A reservation can be created only from an already persisted, immutable, matched, positive-discount `promotion` resolution. The service does not run mutable promotion selection again. It revalidates the resolution's stored rule ID, rule code, rule version, rule configuration hash and configuration snapshot hash against the immutable rule/version rows.

The accepted `BUY-002` Quote is consumed read-only. Reservation requires the Quote to match the resolution subject, Offering, effective pre-discount price, rule-code discount reference, discount amount and IRR currency, and the Quote must still be inside its immutable validity window. The Quote snapshot hash is verified. A zero-final-price Quote remains fail-closed unless the historical promotion rule version explicitly allowed free-order policy input; no free-order execution is created by this increment.

## Authoritative capacity model

The implementation introduces append-only `promotion_usage_reservations` and `promotion_usage_releases` records. Active capacity consumption is derived from reservations that do not have a release record. No mutable usage counter exists and the caller-supplied `observed_total_uses` / `observed_user_uses` fields from rule resolution are never used as capacity authority.

Every reserve and release operation for a rule version locks the same immutable `pricing_rule_versions` row before evaluating or changing capacity state. Under that serialization boundary, global and per-user limits are calculated from committed active reservation rows. Released reservations cease consuming capacity; unreleased reservations continue consuming capacity.

Reservation identity is unique by reservation key, resolution and Quote. Release identity is unique by release key and reservation. Exact replay returns the accepted immutable result. Materially changed key reuse fails closed. A second release under a different key is an invalid terminal transition.

## Authorization and historical identity

The actor user must equal the pricing subject before reservation replay/effect and before release replay/effect. New reservations revalidate that the subject remains an active `customer` or `agent`. Release remains available to the owning subject even if account status later changes so capacity can be explicitly relinquished without fabricating administrator authority.

The reservation snapshots rule/version/configuration identity, resolution/Quote snapshot hashes, subject, Offering, discount, configured limits and free-order permission. A later rule revision or disable cannot reinterpret an existing reservation or exact replay.

## Database integrity

The forward migration `2026_08_09_003100_create_promotion_usage_reservation_foundation.php` adds only new Promotions lifecycle tables and does not edit any integrated migration.

MariaDB controls include:

- FKs to immutable resolution, Quote, user, Offering, rule and rule version identities;
- unique reservation key, release key, resolution claim, Quote claim and one release per reservation;
- positive-discount/version and valid-limit checks;
- SHA-256 format checks and bounded JSON snapshot checks;
- insert guards for active subject, exact promotion resolution/rule/version identity, historical limit/free-order snapshots and immutable Quote binding;
- reservation/release update and delete guards;
- release insert guard binding the release actor and reservation snapshot identity.

No Wallet, ledger, provider, Payment Intent, Order, provisioning or Service effect is introduced.

## Independent-process contention proof

`PromotionUsageReservationContentionVerificationTest` starts independent PHP processes against real CI MariaDB, sets a bounded InnoDB lock wait, uses a deterministic `READY` / `GO` barrier, and has a bounded process-output watchdog. Sleep timing is not used as the correctness mechanism.

Verified scenarios:

1. two users competing for the final global slot cannot both reserve;
2. two reservations for one user competing for the final per-user slot cannot both reserve;
3. concurrent exact duplicate reserve creates one reservation and one replay returning the same reservation identity;
4. reserve-vs-release on the final global slot serializes without oversubscription, and released capacity is reusable.

Focused contention result on implementation CI `#1281`: **4 tests / 16 assertions**, zero failures/errors/skips.

## Focused functional and regression proof

`PromotionUsageReservationFoundationTest`: **10 tests / 44 assertions** covering reserve/replay/conflict, release/replay/terminal conflict, authoritative stored capacity, cross-user denial, inactive-subject denial, no-match/referral rejection, Quote mismatch/expiry rejection, historical rule mutation stability, release-key conflict and MariaDB immutability/forgery guards.

Existing regressions on the same implementation run:

- `PromotionRuleResolutionFoundationTest`: **7 / 74**;
- `QuotePricingSnapshotTest`: **8 / 63**.

The full MariaDB/authenticated-Redis suite passed **413 / 2489**. Repository preflight, Secret scan, Dependency/license policy, PHP static quality and MariaDB/Redis tests all succeeded on the exact implementation head.

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

This document records the exact implementation boundary. The evidence/traceability/risk documentation commit must itself receive all five mandatory CI jobs before Worker handoff. Evidence-head CI/artifact metadata is recorded durably on Issue `#27` and PR `#30` after that run succeeds; this document does not self-assert a future CI outcome.
