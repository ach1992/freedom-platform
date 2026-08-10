# Phase 0.5 PRO-002 benefit-code lifecycle risk record

Issue #33 / W-006 / Contract Revision 2. Risk remains **High** for financial integrity, authorization/security and concurrency.

## Controlled risks

### Code disclosure or offline lookup

Ordinary persistence stores a keyed HMAC lookup hash, authoritative key version and bounded mask only. Lookup HMAC material is dedicated to BenefitCodes and is never derived from `APP_KEY`. Real current/previous lookup keys are environment-provided; committed configuration and evidence contain no real key value. The full generated/owner-selected code is available only in the initial issuance result and is not reconstructable from stored fields.

### Lookup-key rotation and replay instability

BenefitCodes supports an explicit current lookup-key version plus one bounded previous supported version. New issuance uses the current version. Historical rows keep their stored `key_version` and are never rewritten during rotation. Fresh redemption tries only configured supported versions and authenticates the matched row against its stored version; unsupported or incompletely configured historical versions fail closed. Accepted redemption replay authenticates against the accepted redemption's stored code identity rather than a newly computed current-key fingerprint. Owner-chosen issuance replay applies the same stored-version principle. A regression changing only `APP_KEY` proves benefit-code lookup identity is unaffected.

### Cross-version code reissue

Before new owner-chosen or random issuance, the normalized candidate is checked against lookup identities for every still-supported key version. This prevents the same plaintext code from being reissued merely because the current lookup key changed while an older version remains supported. Rotation tests cover v1-to-v2 duplicate prevention.

### Weak/colliding issuance

Random issuance uses the injectable cryptographic RNG with collision retry. Owner-selected input is normalized and strength-validated. Issuance idempotency stores a rotation-stable request fingerprint and validates owner-chosen replay against stored versioned identities; replay never reissues stored plaintext.

### Unauthorized redemption

Application authorization enforces actor==subject and authoritative active customer/agent state. Audience and scope are checked against current authoritative identities. MariaDB guards independently reject forged invalid subjects/scope.

### Capacity races / duplicate effects

Redemption locks the keyed code identity before capacity checks and effect creation. Independent PHP processes prove one final-slot acceptance and exact duplicate replay. Accepted redemptions are immutable and keyed uniquely. Both pre-existing contention tests remain green after Revision 2.

### Ledger lock-order inversion / deadlock

W-006 no longer pre-locks the promotional wallet before the shared funding account. It resolves both ledger-account identities, sorts numeric IDs, and acquires both row locks in ascending order before validating their authoritative state. This aligns W-006 with the accepted `LedgerPostingService` lock order without modifying Wallet/Ledger internals. An independent-process regression with `funding_id < promotional_wallet_id` runs a competing canonical funding-then-wallet transaction; both processes complete without deadlock and only one balanced benefit-code credit effect is created.

### Wallet financial integrity

The capability does not mutate Wallet/Ledger internals or any balance column. Wallet credit calls only the accepted public `LedgerPostingService` and posts one balanced transaction from a dedicated system funding account to an owned active `promotional` wallet bucket. Cash credit is denied. Account class, owner, bucket, currency and active state are all validated while the canonical row locks are held. Accepted history points at the finalized ledger transaction.

### Cross-capability reinterpretation

Redemptions snapshot campaign type/version/configuration identity. Free-service and discount-grant records are immutable future-consumption grants. Later campaign revision/disable or lookup-key rotation cannot reinterpret an accepted redemption.

## Corrected implementation evidence

Corrected implementation HEAD `292d10714549f3e241b8e4494ee20e77252e166d` passed all five mandatory jobs in CI `#1398` / `31333782913` on merge candidate `9bb7d51193e5d5d3ed029251a7cb0f83d7f78677` against integration target `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584`.

Full suite: **463 tests / 3057 assertions**, zero failures/errors. Focused Revision 2 coverage includes lookup-key rotation **4/22**, canonical ledger lock-order contention **1/9**, and existing benefit-code redemption contention **2/12**. Retained test artifact `test-evidence-31333782913`, ID `9043721785`, independently verified SHA-256 `1c589c7f2b60c827794065a90a0355025501fc771fc7c40247ef07256304ef05`.

## Residual / deferred risks

- The supported-key window is intentionally bounded to current plus one previous version. Key retirement must be an explicit operational decision: codes whose stored version is no longer configured become non-redeemable by design and fail closed. A future need for more than one previous online key requires separate review rather than silent widening.
- Key distribution, secret storage, rotation scheduling and retirement operations remain deployment/operations responsibilities; no real secret belongs in repository, CI evidence, PR or Issue text.
- Free-service entitlement consumption remains Phase 0.6 work and must independently prevent duplicate consumption when implemented.
- Discount-grant Quote/PRO-001 consumption remains later work and must bind the stored grant identity without creating a second pricing resolver.
- Financial correction/refund of an accepted wallet-credit redemption must be compensating behavior through the accepted ledger model; deletion or mutation is prohibited.
- Export/delivery UX (including Telegram) is outside this task. A later channel must preserve the one-time exposure boundary and must not log full codes.

## Merge policy

Worker W-006 must not merge PR #38. Independent MASTER review and explicit owner approval are required because the capability crosses financial, security/authorization and concurrency boundaries.
