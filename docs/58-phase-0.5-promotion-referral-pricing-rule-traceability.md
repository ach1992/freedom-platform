# Phase 0.5 Stored Promotion / Referral Pricing-Rule Resolution Traceability

**Status:** implementation-verified Worker evidence candidate; MASTER review/integration pending.  
**Task Contract:** Issue `#25`, Worker `W-001`, Contract Revision `1`.  
**Parent:** Issue `#8`.  
**Requirements:** `PRO-001`, bounded `REF-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**Implementation head:** `2be331fd4fe51ef4f7ee58ca2a82a178128bba09`.  
**Implementation CI:** `31284018788` / `#1256` — all mandatory jobs successful, **399 tests / 2415 assertions**.  
**Evidence:** `evidence/0.5.0/promotion-referral-pricing-rule-resolution.md`.

## Requirement-to-proof map

| Requirement / invariant | Worker implementation proof | Deliberately remaining scope |
|---|---|---|
| `PRO-001` typed promotion identity/configuration | immutable `pricing_rules` identity plus append-only `pricing_rule_versions`; typed kind/state/discount/audience/action domain values | reservation/redemption/release/counters, gift codes, full free-order execution remain later |
| fixed / percentage discount inputs | fixed positive integer IRR or 1–10000 integer basis points with exclusive shape checks | stacking/combination policy beyond deterministic single-winner precedence is not claimed |
| minimum / cap / integer arithmetic | non-negative minimum IRR, optional positive cap, integer-only percentage calculation and capped discount | payment-method adjustment belongs to `PAY-001` |
| effective time | optional UTC start/end definition and resolution-time qualification | no scheduling/activation worker is introduced |
| total / per-user limits | validated positive limits and observed-use qualification inputs | authoritative reservation/redemption counters and contention are explicitly deferred |
| first-purchase input | explicit stored rule flag and request-time prior-success input | Order purchase-history ownership remains later `BUY-001`/Order work |
| audience qualification | `customers|agents|both` plus active current subject account type | no customer segmentation system beyond owned tier/tag/profile data |
| owned scope qualification | optional active tier/tag plus Offering/Product/Sales Server/action scope; scope references are relationally validated | `AGT-005`, `PAY-001`, provider/Target eligibility remain separate |
| bounded `REF-001` pricing identity | `referral` rule kind requires a bounded referral source code and exact request match | referral reward accrual, pending/release, reversal, payout and notifications remain unimplemented |
| precedence / deterministic tie | explicit unsigned priority; exactly one highest-priority qualified rule required; equal highest priority fails closed | no rule stacking engine is claimed |
| no-match result | immutable zero-discount resolution with all rule identity fields absent | no purchase/payment authority follows from no-match/match itself |
| invalid/ambiguous fail closed | domain validation, current-state checks, stored configuration/hash revalidation, ambiguous highest-priority exception with no stored result | malformed future external-rule adapters are outside this increment |
| immutable historical identity | resolution stores stable rule ID, code/kind/version, configuration hash, bounded snapshot/hash; rule versions cannot update/delete | future Quote integration may consume this identity but accepted Quote persistence is unchanged here |
| replay / conflict | unique mutation and resolution keys bound to deterministic payload hashes; exact replay returns accepted record; changed reuse conflicts | future redemption commands require their own idempotency scope |
| `SEC-001` / `SEC-002` | existing server-side administrator authorizer at create/revise/resolve boundary; no secrets/providers introduced | live provider credentials/mutation remain separate protected work |
| `DAT-002` / `DAT-003` | FKs, uniqueness, checks, sequential-version guard, transactions/locks where mutation/resolution writes occur, immutable triggers | future reservation/reward contention requires independent MariaDB concurrency proof |
| `BUY-002` compatibility | Quote persistence/service unchanged; existing Quote suite remains **8 / 63** in exact implementation CI | actual rule-to-Quote orchestration is a later bounded integration |
| `QUA-001` | implementation run `#1256`, full **399 / 2415**, dedicated **7 / 60**, retained artifact ID `9029282011`, independent digest | exact evidence-head gate must also pass before Worker handoff is complete |

## Data ownership

The bounded pricing-rule aggregate owns three new forward-only tables:

1. `pricing_rules` — stable immutable rule identity and kind;
2. `pricing_rule_versions` — append-only configuration versions and authorization/audit context;
3. `pricing_rule_resolutions` — immutable historical matched or explicit no-match outcomes.

No existing applied migration, Quote table, wallet ledger/hold/refund/correction/top-up table, Payment Intent table, Order/provisioning/Service table, provider table, or Telegram attribution table is changed.

Existing Telegram `/start` attribution remains the authoritative first-attribution storage for that ingress path; this task does not reinterpret it into reward execution. The bounded referral pricing rule uses an explicit pricing-source identity only.

## Deterministic resolution contract

Resolution performs the following bounded sequence:

1. authorize the executing administrator;
2. return exact immutable replay when the resolution key/request hash already exists;
3. verify an active current customer/agent and existing Offering;
4. return no-match for zero input price or non-discount-eligible Offering;
5. derive the latest immutable version per stable rule identity;
6. fail closed if stored configuration/hash is invalid;
7. qualify state, audience, minimum, effective window, observed-use limits, first-purchase, tier/tag, Offering/Product/Server/action, and referral source as applicable;
8. choose the unique highest-priority qualified rule;
9. fail closed without persistence when highest priority is ambiguous;
10. calculate fixed or basis-point discount using integer arithmetic, apply the optional cap, and reject full-price discount unless explicitly configured as a free-order policy input;
11. store immutable historical identity/snapshot or an explicit zero-discount no-match result.

Database row order or incidental application iteration order is not a winner selector.

## Historical stability

Rule revision/disable inserts a later immutable version; it does not edit the previous version. Exact replay of an accepted resolution returns the already persisted historical result and configuration identity. A new resolution key evaluates the then-current latest rule versions.

This satisfies the bounded historical-interpretability requirement needed before future Quote integration. It does not mutate or reinterpret an existing Quote.

## Verification

Implementation head `2be331fd4fe51ef4f7ee58ca2a82a178128bba09` / CI `31284018788` (`#1256`):

- all five mandatory Worker jobs successful;
- full MariaDB/authenticated-Redis suite **399 tests / 2415 assertions**;
- `PromotionRuleResolutionFoundationTest` **7 / 60**, zero failures/errors/skips;
- unchanged `QuotePricingSnapshotTest` **8 / 63**, zero failures/errors/skips;
- artifact `test-evidence-31284018788`, ID `9029282011`, retained 30 days;
- independent artifact SHA-256 `ebf72d8ea7822895cea0d306c866358f4d049201503895a13dce87fcce000fae`.

The earlier implementation run `#1255` is diagnostic only because Pint failed on one docblock spacing rule. The formatting-only correction is included in the verified implementation head above.

## Risk disposition

`FIN-18` is reduced at the Worker candidate boundary for stored rule mutation/reinterpretation and nondeterministic qualification: version/configuration identity is immutable, resolution has exact replay/conflict semantics, and equal-priority ambiguity fails closed.

`FIN-18` is **not closed**. Reservation/redemption/release counters, duplicate redemption contention, referral reward effects, payout/reversal, and downstream purchase/payment execution remain separate high-sensitivity gates and must receive their own MariaDB/concurrency/effect evidence.

## Explicit non-claims

No Worker acceptance claim is made for reservation/redemption/release; contention counters; complete free-order execution; `PRO-002`; referral payout/pending/release/reversal/notifications; `AGT-005`; `PAY-001`; payment providers or provider-native refunds; wallet effects; paid Orders; provisioning/Services; PasarGuard/Marzban live operations; Target activation; PR `#24`; Phase closure; or release acceptance.

The Worker PR must be independently reviewed by MASTER and must not be self-merged. Exact evidence-head CI/artifact status is recorded on Issue `#25` and PR `#26` after the evidence commit's mandatory CI succeeds.
