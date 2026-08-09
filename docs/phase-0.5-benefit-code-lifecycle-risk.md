# Phase 0.5 PRO-002 benefit-code lifecycle risk record

Issue #33 / W-006 / Contract Revision 1. Risk remains **High** for financial integrity, authorization/security and concurrency.

## Controlled risks

### Code disclosure or offline lookup

Ordinary persistence stores a keyed HMAC lookup hash, key version and bounded mask only. The full generated/owner-selected code is available only in the initial issuance result and is not reconstructable from stored fields. Evidence and PR/Issue metadata intentionally contain no issued codes.

### Weak/colliding issuance

Random issuance uses the injectable cryptographic RNG with collision retry. Owner-selected input is normalized, strength-validated and keyed-hash uniqueness checked. Issuance idempotency stores request hashes; replay never reissues stored plaintext.

### Unauthorized redemption

Application authorization enforces actor==subject and authoritative active customer/agent state. Audience and scope are checked against current authoritative identities. MariaDB guards independently reject forged invalid subjects/scope.

### Capacity races / duplicate effects

Redemption locks the keyed code identity before capacity checks and effect creation. Independent PHP processes prove one final-slot acceptance and exact duplicate replay. Accepted redemptions are immutable and keyed uniquely.

### Wallet financial integrity

The capability does not mutate Wallet/Ledger internals or any balance column. Wallet credit calls only the accepted public `LedgerPostingService` and posts one balanced transaction from a dedicated system funding account to an owned active `promotional` wallet bucket. Cash credit is denied. Accepted history points at the finalized ledger transaction.

### Cross-capability reinterpretation

Redemptions snapshot campaign type/version/configuration identity. Free-service and discount-grant records are immutable future-consumption grants. Later campaign revision/disable cannot reinterpret an accepted redemption.

## Residual / deferred risks

- Lookup-key rotation beyond key version 1 requires a separately reviewed migration/dual-key lookup plan; this task records key version but does not invent rotation semantics.
- Free-service entitlement consumption remains Phase 0.6 work and must independently prevent duplicate consumption when implemented.
- Discount-grant Quote/PRO-001 consumption remains later work and must bind the stored grant identity without creating a second pricing resolver.
- Financial correction/refund of an accepted wallet-credit redemption must be compensating behavior through the accepted ledger model; deletion or mutation is prohibited.
- Export/delivery UX (including Telegram) is outside this task. A later channel must preserve the one-time exposure boundary and must not log full codes.

## Merge policy

Worker W-006 must not merge PR #38. Independent MASTER review and explicit owner approval are required because the capability crosses financial, security/authorization and concurrency boundaries.
