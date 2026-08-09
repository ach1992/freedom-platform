# PRO-002 secure benefit-code lifecycle evidence

Worker: W-006  
Issue: #33  
Task Contract Revision: 1  
Dispatch BASE_SHA: `a7876668492c115ce2eba1b7194c83ac169ce8a7`

## Implementation identity

- Exact Worker implementation HEAD: `31117d48eef55a03acb4327ebbec01ccbddb7811`
- Live integration target validated by the PR merge candidate: `2ce0462f6439d51f7d29c5f6dddf7d1ed4581584`
- Exact PR merge-candidate checkout: `4017c90fa7414f801ed3c8abe1b3d9ba5a57d563` (`31117d48...` merged into `2ce0462f...`)
- PR: #38, Draft, target `develop/v1.0.0-completion`

## Exact implementation CI

CI `#1387` / run ID `31329987132` passed all five mandatory jobs:

- Repository preflight: PASS
- Secret scan: PASS
- PHP static quality: PASS
- MariaDB and Redis tests: PASS
- Dependency and license policy: PASS

Full suite: **458 tests / 3026 assertions**, zero failures/errors.

Focused evidence from the retained JUnit result:

- `BenefitCodeLifecycleTest`: **9 tests / 115 assertions** — PASS
- `BenefitCodeRedemptionContentionVerificationTest`: **2 / 12** — PASS
- `PromotionRuleResolutionFoundationTest`: **7 / 74** — PASS
- promotion usage reservation/capacity/contention suites combined: **18 / 80** — PASS
- `QuotePricingSnapshotTest`: **8 / 68** — PASS
- `AgentPricingQuoteIntegrationTest`: **11 / 159** — PASS
- `FinancialLedgerFoundationTest`: **5 / 39** — PASS
- `WalletContentionVerificationTest`: **6 / 35** — PASS

Retained test artifact:

- name: `test-evidence-31329987132`
- artifact ID: `9042664207`
- independently downloaded ZIP SHA-256: `ffcab95273e9026dd734145d70b682b180c1fedddf904dbcbef16780fa236bc5`

The digest above was calculated independently from the downloaded ZIP, not copied from the upload action.

## Security / financial evidence

- Ordinary `benefit_codes` storage has no plaintext, encrypted-recovery, or revealable-code column; it stores a keyed lookup hash, key version and bounded display mask only.
- Random issuance uses the repository cryptographic random boundary. Owner-chosen input is normalized, strength-checked and uniqueness-checked.
- Initial issuance may return full code material once. Exact issuance replay reconstructs only stored identities/masks and does not reveal full code material.
- Benefit codes are not included in this evidence, PR/Issue metadata, audit reason payloads, or application persistence snapshots.
- Redemption authorizes actor==subject and authoritative active customer/agent state before effect.
- Code identity is locked before capacity/effect; independent-process final-slot and duplicate redemption tests pass.
- Wallet credit uses only `LedgerPostingService`, credits an owned active `promotional` liability account, and creates one balanced `benefit_code_promotional_credit` ledger transaction per accepted redemption. No cash bucket or balance column is edited.
- Free-service redemption creates only an immutable entitlement record. Discount-grant redemption creates only an immutable grant referencing an accepted PRO-001 promotion-rule identity/version.
- No Order, Service, provisioning, Payment Intent/provider, remote identity, Quote mutation, second promotion resolver, or accepted Promotion/Wallet behavior modification is introduced.

## Schema / immutability evidence

Forward-only schema begins at `2026_08_09_003500_create_benefit_code_lifecycle.php`. MariaDB constraints and triggers cover identity/version hashes, append-only accepted histories, code disable history, authoritative redemption policies, scope/capacity, and wallet-effect identity. The suite exercises forged/update/delete rejection and accepted replay stability after later configuration changes.

## Evidence-head lifecycle

This file and the bounded traceability/risk files are committed separately from implementation. The exact evidence/current PR head must pass the same five mandatory jobs before READY_FOR_REVIEW; its run and retained artifact digest are recorded in the durable PR/Issue handoff rather than by another evidence commit that would change the candidate SHA.
