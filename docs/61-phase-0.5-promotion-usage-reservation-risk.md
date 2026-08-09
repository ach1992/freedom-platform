# Phase 0.5 Promotion Usage Reservation / Release Risk Note

**Status:** bounded Worker correction risk disposition; evidence-head CI and MASTER re-review pending.  
**Task Contract:** Issue `#27`, Worker `W-002`, Contract Revision `2`.  
**Previous reviewed head:** `8801b317c1fc2102b62e6f5b0c6aec26496fa94d`.  
**Corrected implementation head:** `64dd7b52dd07395ca0d39924cb5812e41b2ec945`.  
**Implementation CI:** `31292316629` / `#1299` — all five mandatory jobs successful.  
**Evidence:** `evidence/0.5.0/promotion-usage-reservation-capacity.md`.  
**Traceability:** `docs/60-phase-0.5-promotion-usage-reservation-traceability.md`.

## Risk disposition

This increment reduces the high financial/concurrency residual risk previously left open by the promotion-resolution foundation, but only for **reservation capacity and explicit release**. It does not close complete `PRO-001` and does not claim payment-success consumption.

The MASTER correction identified that the previously reviewed implementation treated immutable `pricing_rule_version_id` as the capacity domain. That would let an administrator create version 2 of the same stable promotion rule and unintentionally receive a fresh capacity pool. The corrected implementation treats stable `pricing_rule_id` as the authoritative capacity and serialization boundary while retaining the matched immutable version as configuration provenance and as the source of the new reservation's limit policy.

| Risk | Control in Revision 2 correction | Residual / exit condition | Status |
|---|---|---|---|
| rule revision silently resets promotion capacity | all unreleased reservations with the same stable `pricing_rule_id` are counted across versions; new reservation limits still come from the matched immutable version | future policy must not introduce a reset without explicit product authority and migration semantics | Controlled for reservation |
| global promotion capacity oversubscribed under concurrent reserve | effectful reserve locks the stable `pricing_rules` row before version/capacity work; active same-rule reservations are counted inside the transaction; same-version and cross-version final-slot subprocess proof | preserve stable-rule authority when future redeem/finalize is added | Controlled for reservation / High later financial gate remains |
| per-user capacity oversubscribed across revisions | same stable-rule serialization plus authoritative unreleased reservations filtered by subject across all versions; no caller remaining-capacity input | future permanent redeemed consumption must remain authoritative under contention | Controlled for reservation |
| reserve/release on different versions race around capacity | effectful release locks the same stable rule row before historical version/reservation transition; released old-version reservation can free later-version capacity | automatic payment-driven release orchestration remains absent | Controlled for explicit release |
| caller-provided observed counters bypass capacity | `observed_total_uses` and `observed_user_uses` are never read by reservation service | none for this boundary; regress if future code starts trusting them | Controlled |
| duplicate retry creates two reservations | unique reservation key, resolution claim and Quote claim; exact payload replay returns original row; duplicate independent-process proof | future redeem requires separate idempotency/effect identity | Controlled for reservation |
| released capacity remains stuck | release is append-only and active-capacity query excludes released reservations; same-version and cross-version reuse proven | automatic release orchestration still absent | Controlled for explicit release / operational integration open |
| same reservation released twice or release key reused materially | unique release key, one release per reservation, payload hash and terminal transition check | future redeemed terminal state must become mutually exclusive with release | Controlled for Revision 2 state space |
| cross-user replay leaks or changes capacity | actor subject check occurs before reserve/release replay/effect; new reserve also revalidates active pricing subject | presentation layer must pass authenticated user identity | Controlled at Application Service boundary |
| mutable promotion change reinterprets held reservation | immutable resolution/rule/version/configuration and bounded reservation snapshot; stable-rule capacity accounting does not rewrite version provenance | future payment consumption must use stored reservation identity, never current rule | Controlled for reservation history |
| no-match/referral resolution consumes promotion capacity | reserve requires positive matched `promotion` resolution and DB guard independently verifies kind/identity | referral reward lifecycle is separate | Controlled |
| forged resolution/Quote identity manufactures capacity use | application and DB guards join exact immutable resolution/rule/version/Quote identity and verify hashes/context | direct privileged DB operators remain outside application threat boundary | Controlled within application/database contract |
| expired or incompatible Quote reserves discount | subject/Offering/effective-price/reference/value/currency/hash/window are validated read-only at reserve time | purchase/order orchestration still must consume the same accepted Quote/reservation | Controlled for reservation binding |
| free-order policy silently creates free purchase | zero-final-price Quote requires historical `allows_free_order`; no Order/payment execution is introduced | explicit free-order execution policy remains a later purchase gate | Fail-closed |
| release orchestration never runs for failed/expired/canceled payment | no Payment Intent integration is implemented by design | add only after genuine purchase-bound Payment Intent/Order authority exists | Open / explicitly deferred |
| wallet top-up mistaken for purchase authority | Revision 1 architecture blocker is preserved; service has no Payment dependency and never interprets wallet-top-up capture | genuine purchase-bound payment authority required before redemption | Controlled by absence / later architecture gate |
| successful-payment redemption/finalization absent | explicitly out of scope in Revision 2 | separate Task Contract after purchase-bound payment authority exists, with terminal race/contention proof | Open / High financial gate |
| append-only history rewritten | reservation/release update and delete triggers reject mutation; FKs restrict deletion of dependencies | future redeem must also be append-only | Controlled for this boundary |
| concurrent Worker W-003 conflict | implementation stays in Promotions and dedicated tests/docs; no Agents/agent-pricing files changed | MASTER must evaluate current target at integration time | Low/Medium integration review |

## Financial safety observations

1. Capacity authority is relational state keyed by stable `pricing_rule_id`, not a mutable cached counter and not a version-local pool.
2. New reservation policy remains version-specific: the matched immutable rule version supplies global/per-user limits and free-order permission.
3. Effectful reserve/release operations share one stable `pricing_rules` row lock across versions before version/reservation capacity work; rule revision uses that same stable identity boundary.
4. Release compensates capacity by adding history rather than deleting or rewriting a reservation; an old-version release can free capacity for a newer version when its configured limit permits.
5. Monetary values remain integer IRR; no float is introduced.
6. Quote binding is pricing-context validation only and cannot be upgraded into payment or Order authority by this module.
7. The reservation lifecycle creates no wallet, ledger, payment, provider, Order, provisioning or Service side effect.

## Evidence-backed risk proof

Corrected implementation CI `31292316629` / `#1299` passed all five mandatory self-hosted jobs. Full suite: **417 tests / 2509 assertions**. Focused reservation suites: **18 / 80**, including combined promotion usage contention **5 / 22**. Correction-specific cross-version proof is **4 / 20**: three functional tests (**3 / 14**) plus one independent-process MariaDB contention test (**1 / 6**). Existing promotion resolution **7 / 74** and Quote **8 / 63** remain green.

Artifact `test-evidence-31292316629`, ID `9031813877`, independently downloaded SHA-256 `0a50795f2f9bf0ddf97ab72b1d91f3ccbc4ceb31ad52946116a0631fdaa04fa6`, matching uploader digest.

## Merge-risk note

This is a high-risk financial/concurrency change. Worker evidence is not merge approval. Independent MASTER review and explicit human approval remain required. MASTER must review the actual current PR head, target compatibility, exact evidence-head CI and protected-scope diff before integration.

## Explicit residual non-acceptance

The following remain unaccepted after this Worker increment:

- successful-payment promotion redemption/finalization;
- automatic release from failed/expired/canceled purchase Payment Intents;
- complete `PRO-001`;
- purchase-bound Payment Intent authority;
- Orders;
- `PAY-001`;
- provider behavior;
- Wallet/ledger effects;
- referral rewards, `PRO-002`, `AGT-005`;
- provisioning/Services;
- Phase `0.5.0` closure or production release readiness.
