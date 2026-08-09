# Phase 0.5 PRO-002 benefit-code lifecycle traceability

Issue #33 / W-006 / Contract Revision 2.

| Contract criterion | Implementation boundary | Verification |
|---|---|---|
| Secure single/batch issuance | `BenefitCodeCodec`, `BenefitCodeIssuanceSupport`, injectable shared `RandomGenerator` | `BenefitCodeLifecycleTest` random single/batch + collision tests |
| Dedicated lookup key independent from `APP_KEY` | `config/benefit_codes.php`, `HmacBenefitCodeLookupHasher`; environment-only current/previous key material | lookup rotation + `APP_KEY` independence tests; Secret scan |
| Version-aware lookup rotation | explicit current version plus bounded previous supported version; stored `benefit_codes.key_version` authoritative | v1 issue, rotate to v2 retaining v1, old v1 redemption, new v2 issuance |
| Rotation-stable redemption replay | replay authenticates submitted code against accepted redemption's stored code/version; request fingerprint excludes current-key hash | old accepted redemption exact replay after v1 -> v2 rotation |
| Rotation-stable owner-chosen issuance replay | issuance fingerprint excludes current-key hash; replay authenticates each submitted normalized code against stored versioned identity | owner-chosen exact replay after rotation |
| Cross-version duplicate prevention | candidate normalized code checked against HMAC identities for every still-supported version | same owner-chosen plaintext cannot be reissued across v1/v2 |
| Unsupported/missing historical key fail-closed | hasher rejects incomplete key configuration; fresh lookup only considers explicitly supported versions | unsupported v1 after retirement and missing previous-key tests |
| Owner-chosen normalization/strength/uniqueness | `BenefitCodeCodec`, version-aware supported-key lookup | owner-chosen normalization/strength/collision + rotation duplicate tests |
| No plaintext/secret at rest or evidence / one-time exposure | `benefit_codes` keyed hash + key version + mask; issuance replay has no plaintext recovery | storage-column/query-binding assertions, rotation leak assertions, Secret scan |
| Stable typed campaign identity and append-only versions | BenefitCodes domain definitions, management/persistence support, `benefit_code_campaign_versions` guards | management replay/conflict, version stability, forged DB tests |
| State/window/audience/scope/limits | immutable campaign configuration snapshots + application and MariaDB redemption checks | disabled/not-yet-effective/expired/audience/scope/total/per-user tests |
| Runtime management authorization | `AdministratorPermissionAuthorizer`, dedicated `promotions.benefit_codes.manage` permission | allowed/denied management tests |
| Runtime subject authorization | actor==subject plus authoritative active customer/agent state | cross-user/subject tests and DB guards |
| Exact redemption replay/conflict | immutable request hash + unique redemption key + stored versioned code authentication | replay/conflict tests and duplicate contention test |
| Independent-process final-slot safety | `benefit_codes` row lock before capacity/effect plus DB guard | `BenefitCodeRedemptionContentionVerificationTest` final-slot case |
| Independent-process duplicate safety | locked code identity + redemption-key replay | duplicate contention case; one accepted history/effect |
| Canonical ledger-account lock ordering | funding + promotional-wallet identities resolved then `ledger_accounts` locked in ascending numeric ID order before validation | `BenefitCodeLedgerLockOrderingContentionTest`, with `funding_id < wallet_id`, competing canonical lock transaction and no deadlock |
| Wallet promotional credit only | public `LedgerPostingService`; funding debit + owned promotional wallet credit | wallet exactly-once/balanced/cash-denial tests; ledger regressions |
| Free-service bounded effect | immutable `benefit_code_free_service_entitlements` record only | entitlement test; no Order/Service/provider side effect assertions |
| Discount-grant bounded effect | immutable `benefit_code_discount_grants` snapshot of accepted PRO-001 identity/version | grant test; Promotion/Quote regression suites |
| Immutable accepted history | update/delete guards and snapshot/hash identities | DB forged/update/delete rejection; later revision/disable/key-rotation replay stability |
| Accepted PRO-001/W-002/Quote/AgentPricing/Wallet compatibility | no behavior edits to accepted services; read/call boundaries only | full suite plus focused Promotion/Quote/AgentPricing/Ledger/Wallet regressions |

## Corrected implementation validation

Corrected implementation HEAD `292d10714549f3e241b8e4494ee20e77252e166d` passed all five mandatory jobs in CI `#1398` / `31333782913` against merge candidate `9bb7d51193e5d5d3ed029251a7cb0f83d7f78677`, merging into integration target `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584`.

Full suite: **463 tests / 3057 assertions**. W-006 focused counts: lifecycle **9/115**, lookup-key rotation **4/22**, ledger lock-order contention **1/9**, existing redemption contention **2/12**. Retained artifact `test-evidence-31333782913` / ID `9043721785`, independently verified SHA-256 `1c589c7f2b60c827794065a90a0355025501fc771fc7c40247ef07256304ef05`.

## Non-claims

This task does not consume a free-service entitlement in Phase 0.6, wire a discount grant into Quote/PRO-001, implement referral reward effects, create an Order or Service, call a provider, create a Payment Intent, or implement correction/refund behavior. Revision 2 also does not change accepted Wallet/Ledger locking/posting internals; it only aligns W-006 pre-locking with their canonical order. Those remain separately owned boundaries.
