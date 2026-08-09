# Phase 0.5 PRO-002 benefit-code lifecycle traceability

Issue #33 / W-006 / Contract Revision 1.

| Contract criterion | Implementation boundary | Verification |
|---|---|---|
| Secure single/batch issuance | `BenefitCodeCodec`, `BenefitCodeIssuanceSupport`, injectable shared `RandomGenerator` | `BenefitCodeLifecycleTest` random single/batch + collision tests |
| Owner-chosen normalization/strength/uniqueness | `BenefitCodeCodec`, keyed lookup hash | owner-chosen normalization/strength/collision tests |
| No plaintext at rest / one-time exposure | `benefit_codes` keyed hash + key version + mask; issuance replay has no plaintext recovery | storage-column/query-binding assertions and issuance replay assertions |
| Stable typed campaign identity and append-only versions | BenefitCodes domain definitions, management/persistence support, `benefit_code_campaign_versions` guards | management replay/conflict, version stability, forged DB tests |
| State/window/audience/scope/limits | immutable campaign configuration snapshots + application and MariaDB redemption checks | disabled/not-yet-effective/expired/audience/scope/total/per-user tests |
| Runtime management authorization | `AdministratorPermissionAuthorizer`, dedicated `promotions.benefit_codes.manage` permission | allowed/denied management tests |
| Runtime subject authorization | actor==subject plus authoritative active customer/agent state | cross-user/subject tests and DB guards |
| Exact redemption replay/conflict | immutable request hash + unique redemption key + locked lookup identity | replay/conflict tests and duplicate contention test |
| Independent-process final-slot safety | `benefit_codes` row lock before capacity/effect plus DB guard | `BenefitCodeRedemptionContentionVerificationTest` final-slot case |
| Independent-process duplicate safety | locked code identity + redemption-key replay | duplicate contention case; one accepted history/effect |
| Wallet promotional credit only | public `LedgerPostingService`; funding debit + owned promotional wallet credit | wallet exactly-once/balanced/cash-denial tests; ledger regressions |
| Free-service bounded effect | immutable `benefit_code_free_service_entitlements` record only | entitlement test; no Order/Service/provider side effect assertions |
| Discount-grant bounded effect | immutable `benefit_code_discount_grants` snapshot of accepted PRO-001 identity/version | grant test; Promotion/Quote regression suites |
| Immutable accepted history | update/delete guards and snapshot/hash identities | DB forged/update/delete rejection; later revision/disable replay stability |
| Accepted PRO-001/W-002/Quote/Wallet compatibility | no behavior edits to accepted services; read/call boundaries only | full suite plus focused Promotion/Quote/Ledger/Wallet regressions |

## Non-claims

This task does not consume a free-service entitlement in Phase 0.6, wire a discount grant into Quote/PRO-001, implement referral reward effects, create an Order or Service, call a provider, create a Payment Intent, or implement correction/refund behavior. Those remain separately owned later capabilities.
