# PRO-002 secure benefit-code lifecycle evidence

Worker: W-006  
Issue: #33  
Task Contract Revision: 2  
Dispatch BASE_SHA: `a7876668492c115ce2eba1b7194c83ac169ce8a7`

## Corrected implementation identity

- Exact corrected Worker implementation HEAD: `292d10714549f3e241b8e4494ee20e77252e166d`
- Live integration target validated by the PR merge candidate: `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584`
- Exact PR merge-candidate checkout: `9bb7d51193e5d5d3ed029251a7cb0f83d7f78677` (`292d1071...` merged into `2ce0462f...`)
- PR: #38, Draft, target `develop/v1.0.0-completion`

Revision 2 preserves the accepted Revision 1 capability and corrects two High-risk review findings: dedicated version-aware benefit-code lookup keys independent from `APP_KEY`, and canonical ascending ledger-account lock ordering before wallet-credit posting.

## Exact corrected implementation CI

CI `#1398` / run ID `31333782913` passed all five mandatory jobs:

- Repository preflight: PASS
- Secret scan: PASS
- PHP static quality: PASS
- MariaDB and Redis tests: PASS
- Dependency and license policy: PASS

Full suite: **463 tests / 3057 assertions**, zero failures/errors.

Focused evidence from the retained JUnit result:

- `BenefitCodeLifecycleTest`: **9 tests / 115 assertions** — PASS
- `BenefitCodeLookupKeyRotationTest`: **4 / 22** — PASS
- `BenefitCodeLedgerLockOrderingContentionTest`: **1 / 9** — PASS
- `BenefitCodeRedemptionContentionVerificationTest`: **2 / 12** — PASS
- `PromotionRuleResolutionFoundationTest`: **7 / 74** — PASS
- promotion usage reservation/capacity/contention suites combined: **18 / 80** — PASS
- `QuotePricingSnapshotTest`: **8 / 68** — PASS
- `AgentPricingQuoteIntegrationTest`: **11 / 159** — PASS
- `FinancialLedgerFoundationTest`: **5 / 39** — PASS
- `WalletContentionVerificationTest`: **6 / 35** — PASS

Retained corrected implementation test artifact:

- name: `test-evidence-31333782913`
- artifact ID: `9043721785`
- independently downloaded ZIP SHA-256: `1c589c7f2b60c827794065a90a0355025501fc771fc7c40247ef07256304ef05`

The digest above was calculated independently from the downloaded ZIP and matches the GitHub-reported artifact digest.

## Dedicated lookup-key lifecycle evidence

- `HmacBenefitCodeLookupHasher` no longer reads or derives from `APP_KEY`; BenefitCodes has dedicated configuration for an explicit current lookup-key version and one bounded previous supported version.
- Real lookup-key material is environment-provided and absent from committed configuration/evidence. `.env.example` contains only blank placeholders; CI uses explicitly non-production test-only material.
- New issuance stores the configured current `key_version`; existing `benefit_codes.key_version` remains authoritative for historical lookup authentication.
- Redemption computes candidate hashes only for supported versions, validates the matched row against its stored key version, and fails closed for missing/unknown/unsupported historical key material.
- Accepted redemption replay authenticates the supplied code against the accepted redemption's stored code identity/version instead of blindly fingerprinting with the new current key.
- Owner-chosen issuance replay verifies submitted normalized codes against stored versioned identities and remains stable after current-key rotation.
- Reissue protection checks the normalized candidate against every still-supported version, preventing the same plaintext code from being issued again under a new supported key version.
- A dedicated regression changes only `APP_KEY` and proves benefit-code lookup identity/redemption remains unchanged.
- Rotation/security tests verify no full code, normalized plaintext, or lookup-key material appears in observed query bindings; Secret scan also passes.

## Canonical ledger lock-order evidence

- Wallet-credit redemption resolves the funding and promotional-wallet account IDs, sorts both numeric IDs, and acquires `ledger_accounts` row locks in ascending order before validating account class, ownership, bucket, currency and active state.
- The accepted `LedgerPostingService` is unchanged and remains the only financial posting boundary.
- `BenefitCodeLedgerLockOrderingContentionTest` uses independent PHP processes with `funding_id < promotional_wallet_id`; a competing transaction takes the same canonical funding-then-wallet order while redemption executes concurrently. Both complete without deadlock, with one accepted redemption and one balanced benefit-code promotional-credit ledger effect.
- Existing final-slot and exact-duplicate redemption contention tests remain green.

## Security / financial evidence

- Ordinary `benefit_codes` storage has no plaintext, encrypted-recovery, or revealable-code column; it stores a keyed lookup hash, key version and bounded display mask only.
- Random issuance uses the repository cryptographic random boundary. Owner-chosen input is normalized, strength-checked and uniqueness-checked.
- Initial issuance may return full code material once. Exact issuance replay reconstructs only stored identities/masks and does not reveal full code material.
- Benefit codes and lookup secrets are not included in this evidence, PR/Issue metadata, audit reason payloads, or application persistence snapshots.
- Redemption authorizes actor==subject and authoritative active customer/agent state before effect.
- Wallet credit uses only `LedgerPostingService`, credits an owned active `promotional` liability account, and creates one balanced `benefit_code_promotional_credit` ledger transaction per accepted redemption. No cash bucket or balance column is edited.
- Free-service redemption creates only an immutable entitlement record. Discount-grant redemption creates only an immutable grant referencing an accepted PRO-001 promotion-rule identity/version.
- No Order, Service, provisioning, Payment Intent/provider, remote identity, Quote mutation, second promotion resolver, or accepted Promotion/Wallet behavior modification is introduced.

## Schema / immutability evidence

Forward-only schema remains `2026_08_09_003500_create_benefit_code_lifecycle.php`. Revision 2 does not rewrite applied schema. Existing MariaDB constraints/triggers continue to cover identity/version hashes, append-only accepted histories, disable history, authoritative redemption policies, scope/capacity, and wallet-effect identity. The suite exercises forged/update/delete rejection and replay stability across later configuration and lookup-key changes.

## Evidence-head lifecycle

This file and the bounded traceability/risk files are updated separately from corrected implementation. The exact evidence/current PR head must pass the same five mandatory jobs before READY_FOR_REVIEW; its run, merge candidate and retained artifact digest are recorded in the durable PR/Issue handoff rather than by another evidence commit that would change the candidate SHA.
