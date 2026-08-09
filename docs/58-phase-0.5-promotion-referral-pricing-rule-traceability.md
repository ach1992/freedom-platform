# Phase 0.5 Stored Promotion / Referral Pricing-Rule Resolution Traceability

**Status:** corrected implementation-verified Worker evidence candidate; MASTER review/integration pending.  
**Task Contract:** Issue `#25`, Worker `W-001`, Contract Revision `2`.  
**Parent:** Issue `#8`.  
**Requirements:** `PRO-001`, bounded `REF-001`, supporting `BUY-002`, `DAT-002`, `DAT-003`, `SEC-001`, `SEC-002`, `QUA-001`.  
**Previous reviewed head:** `836adbfdf5380dae4854671f22de83cd3f0a3a69`.  
**Corrected implementation head:** `6724d570a73bd470d70b8d17f9e0422bec0c5257`.  
**Implementation CI:** `31285667745` / `#1266` — all mandatory jobs successful, **399 tests / 2429 assertions**.  
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
| audience qualification | `customers|agents|both` plus authoritative active current subject account type | no customer segmentation system beyond owned tier/tag/profile data |
| owned scope qualification | optional active tier/tag plus Offering/Product/Sales Server/action scope; scope references are relationally validated | `AGT-005`, `PAY-001`, provider/Target eligibility remain separate |
| bounded `REF-001` pricing identity | `referral` rule kind requires a bounded referral source code and exact request match | referral reward accrual, pending/release, reversal, payout and notifications remain unimplemented |
| precedence / deterministic tie | explicit unsigned priority; exactly one highest-priority qualified rule required; equal highest priority fails closed | no rule stacking engine is claimed |
| no-match result | immutable zero-discount resolution with all rule identity fields absent | no purchase/payment authority follows from no-match/match itself |
| invalid/ambiguous fail closed | domain validation, current-state checks, stored configuration/hash revalidation, ambiguous highest-priority exception with no stored result | malformed future external-rule adapters are outside this increment |
| immutable historical identity | resolution stores stable rule ID, code/kind/version, configuration hash, bounded snapshot/hash; rule versions cannot update/delete | future Quote integration may consume this identity but accepted Quote persistence is unchanged here |
| replay / conflict | unique mutation/resolution keys bound to deterministic payload hashes; exact replay returns accepted record; changed reuse conflicts | future redemption commands require their own idempotency scope |
| resolution subject authorization | typed `PromotionResolutionContext`; actor user must equal pricing subject before replay/persistence; active `customer|agent` is revalidated from DB; cross-user use fails closed | later HTTP/bot/Quote orchestration must supply the authenticated legitimate user context without inventing administrator authority |
| admin rule-management authorization | existing `AdministratorPermissionAuthorizer` and `promotions.rules.manage` remain mandatory for create/revise; version audit retains acting administrator/reason/correlation | no administrator authority is required or fabricated for customer/agent pricing resolution |
| `SEC-001` / `SEC-002` | server-side admin authorization on management plus explicit subject-user authorization on pricing resolution; no secrets/providers introduced | live provider credentials/mutation remain separate protected work |
| `DAT-002` / `DAT-003` | FKs, uniqueness, checks, active-subject insert guard, sequential-version guard, transactions/locks, immutable triggers | future reservation/reward contention requires independent MariaDB concurrency proof |
| `BUY-002` compatibility | Quote persistence/service unchanged; existing Quote suite remains **8 / 63** in exact implementation CI | actual rule-to-Quote orchestration is a later bounded integration |
| `QUA-001` | implementation run `#1266`, full **399 / 2429**, dedicated **7 / 74**, retained artifact ID `9029733870`, independent digest | exact evidence-head gate must also pass before Worker handoff is complete |

## Data ownership and provenance

The bounded pricing-rule aggregate owns three new branch-only tables:

1. `pricing_rules` — stable immutable rule identity and kind;
2. `pricing_rule_versions` — append-only configuration versions with administrator mutation/audit context;
3. `pricing_rule_resolutions` — immutable historical matched or explicit no-match outcomes keyed to the pricing subject user and semantic request.

`pricing_rule_resolutions` deliberately has no `resolved_by_administrator_id`. Resolution provenance is actor-neutral for the customer/agent Quote path: immutable `user_id`, semantic request hash, Offering/action/input context, historical rule/version/configuration identity, and snapshot hash. The application boundary requires the invoking user actor to equal that subject before exact replay or new persistence.

No existing applied migration, Quote table, wallet ledger/hold/refund/correction/top-up table, Payment Intent table, Order/provisioning/Service table, provider table, or Telegram attribution table is changed. Contract Revision 2 explicitly permits correction in place of the promotion migration because it exists only on this unmerged Worker branch.

Existing Telegram `/start` attribution remains the authoritative first-attribution storage for that ingress path; this task does not reinterpret it into reward execution. The bounded referral pricing rule uses an explicit pricing-source identity only.

## Deterministic resolution contract

Resolution performs the following bounded sequence:

1. require the typed invoking `actorUserId` to equal the requested pricing `userId`; mismatch fails closed before replay;
2. return exact immutable replay when the resolution key and semantic request hash match;
3. for a new result, verify authoritative current state is an active `customer` or `agent` and verify the Offering exists;
4. return no-match for zero input price or non-discount-eligible Offering;
5. derive the latest immutable version per stable rule identity;
6. fail closed if stored configuration/hash is invalid;
7. qualify state, audience, minimum, effective window, observed-use limits, first-purchase, tier/tag, Offering/Product/Server/action, and referral source as applicable;
8. choose the unique highest-priority qualified rule;
9. fail closed without persistence when highest priority is ambiguous;
10. calculate fixed or basis-point discount using integer arithmetic, apply the optional cap, and reject full-price discount unless explicitly configured as a free-order policy input;
11. store immutable historical identity/snapshot or an explicit zero-discount no-match result.

Database row order or incidental application iteration order is not a winner selector. Administrator identity is not part of resolution replay identity; pricing subject and semantic pricing inputs are.

## Management authorization remains unchanged

Rule mutation is not customer authority. `create()` and `revise()` continue to require execution-time administrator authorization through `promotions.rules.manage` and retain administrator/reason/correlation provenance on immutable rule versions. The correction removes only the inappropriate administrator dependency from pricing resolution; it does not weaken management authorization.

No Scheduler, Worker, background service, or future Quote integration is modeled as an administrator by this boundary.

## Historical stability

Rule revision/disable inserts a later immutable version; it does not edit the previous version. Exact same-subject replay of an accepted resolution returns the already persisted historical result and configuration identity. A new resolution key evaluates the then-current latest rule versions. A different user cannot obtain that replay because subject authorization is enforced first.

This satisfies the bounded historical-interpretability requirement needed before future Quote integration. It does not mutate or reinterpret an existing Quote.

## Verification

Corrected implementation head `6724d570a73bd470d70b8d17f9e0422bec0c5257` / CI `31285667745` (`#1266`):

- all five mandatory Worker jobs successful;
- full MariaDB/authenticated-Redis suite **399 tests / 2429 assertions**;
- `PromotionRuleResolutionFoundationTest` **7 / 74**, zero failures/errors/skips;
- unchanged `QuotePricingSnapshotTest` **8 / 63**, zero failures/errors/skips;
- artifact `test-evidence-31285667745`, ID `9029733870`, size `119611` bytes, retained 30 days;
- uploader and independent artifact SHA-256 `3657ac08fbc1ef75d413018e27c3950f4f58bfbe4e7c8bd13be9b1498fcc3f1c`.

Run `31285597971` / `#1265` is diagnostic only because Pint found a missing final newline in the branch-only migration. The verified implementation head includes that formatting-only correction.

## Risk disposition

`FIN-18` is reduced at the Worker candidate boundary for stored rule mutation/reinterpretation, nondeterministic qualification, cross-user pricing-resolution authority, and administrator-identity coupling: version/configuration identity is immutable, legitimate customer/agent subject authorization is explicit, resolution has exact replay/conflict semantics, and equal-priority ambiguity fails closed.

`FIN-18` is **not closed**. Reservation/redemption/release counters, duplicate redemption contention, referral reward effects, payout/reversal, and downstream purchase/payment execution remain separate high-sensitivity gates and must receive their own MariaDB/concurrency/effect evidence.

## Explicit non-claims

No Worker acceptance claim is made for reservation/redemption/release; contention counters; complete free-order execution; `PRO-002`; referral payout/pending/release/reversal/notifications; `AGT-005`; `PAY-001`; payment providers or provider-native refunds; wallet effects; paid Orders; actual Quote orchestration consuming this rule resolution; provisioning/Services; PasarGuard/Marzban live operations; Target activation; PR `#24`; Phase closure; or release acceptance.

The Worker PR must be independently reviewed by MASTER and must not be self-merged. Exact evidence-head CI/artifact status is recorded on Issue `#25` and PR `#26` after the evidence commit's mandatory CI succeeds.
