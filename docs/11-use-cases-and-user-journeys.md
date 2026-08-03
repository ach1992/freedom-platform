# Use Cases and User Journeys

Document status: `planned`  
Implementation/test status: `not-started`

These acceptance journeys are presentation-level contracts. Every step invokes Application Services; Telegram handlers contain no financial, authorization, or provisioning rules. Repeated updates/callbacks/actions return the prior result. Every flow supports localized Back, Cancel, expiry, and safe restart where applicable.

## Actor and policy model

| Actor | Scope |
|---|---|
| Customer | Own profile, wallet, orders, payments, services, referrals, tickets, and policy-visible content |
| Agent | Customer scope plus approved agent pricing, bulk orders, purchased-service/report scope |
| Administrator | Explicit permission scope only; may also be customer/agent independently |
| Owner | Full administrative scope plus hardened ownership/restore/update/high-risk actions |
| Scheduler/Worker | Service identity with task-specific authority; never inherits user/admin authority |
| External Provider | Untrusted input until authenticated, normalized, and authoritatively verified |

## `UC-01` — Onboard and verify identity

**Requirements:** `ONB-001`–`ONB-005`, `USR-001`, `USR-002`, `CHN-001`, `SEC-009`

**Preconditions:** Telegram webhook is healthy; localized defaults exist.

1. User sends `/start`; the update is persisted once and acknowledged quickly.
2. System upserts the Telegram account by immutable Telegram ID and accepts a valid first referral payload only.
3. Applicable account/maintenance and channel membership policies are evaluated.
4. When required, the user shares a contact whose `contact.user_id` matches, completes OTP, or both.
5. Phone is normalized, uniqueness and abuse limits are checked, proof method/policy version is stored, and safe success/failure copy is shown.
6. The system shows the correct customer, agent, and/or administrator entry points without conflating the identities.

**Alternates:** membership API failure follows rule-specific fail policy; uncertain SMS send does not trigger provider fallback; blocked/limited states retain only explicitly allowed support/service views; administrator re-verification invalidates old proof.

## `UC-02` — Standard purchase and delivery

**Requirements:** `BUY-001`–`BUY-003`, `PAY-001`–`PAY-003`, `CAT-002`–`CAT-005`, `CAT-008`, `PRV-002`, `PRV-003`, `SVC-002`

1. Customer passes account, membership, identity, capacity, and eligibility checks.
2. Customer selects mode/category, plan, allowed server/automatic choice, and optional protocol/username.
3. System validates availability/capabilities and creates an expiring quote with immutable configuration and price components.
4. Discount reservation is evaluated and only eligible payment methods are shown.
5. One method creates one Payment Intent; order remains unpaid until authoritative capture.
6. Capture transactionally selects the winning settlement and queues one provisioning operation per item.
7. Provisioner uses a deterministic key, discovers remote state before uncertain retries, validates the remote object, then records the service.
8. Delivery sends policy-selected links/configurations/QR and records attempts. Delivery failure never changes captured payment or creates another service.

**Alternates:** capacity/fallback policy is disclosed before payment; no compatible target moves order to review; expired quote requires explicit re-quote; conflicting remote identity moves to review; tracking remains visible during delay.

## `UC-03` — Card-to-card payment

**Requirements:** `C2C-001`–`C2C-005`, `PAY-002`, `PAY-003`

1. System selects an eligible destination and atomically reserves a collision-free payable amount: base IRR plus configured 100–999 Toman adjustment.
2. Customer sees exact Toman amount, masked destination, expiry, and instructions; optional receipt metadata is privately submitted.
3. Fake/Generic provider ingests signed webhook and/or cursor-based pulls, deduplicates, normalizes explicit IRR/Toman units, and stores evidence safely.
4. Matcher applies hard settled/destination/exact-amount/time/uniqueness/eligibility rules.
5. Exactly one unambiguous low-risk match may atomically consume the bank transaction and capture the Payment Intent once.
6. All other accepted cases enter authorized manual review with reason/evidence and race-safe decision.
7. Reconciliation detects late/reversed/unmatched/duplicate/cursor cases; reversal after fulfilment opens a Critical incident.

**Invariant:** receipt image, sender statement, browser screen, or score alone is not authority. Adjustment is paid but not refundable.

## `UC-04` — Gift-card payment

**Requirements:** `GFT-001`–`GFT-004`, `PAY-002`, `PAY-003`

1. Customer selects a type and supplies image/code/both as its policy requires.
2. Code is normalized, uniqueness checked by keyed hash, recoverable value encrypted only when required, and later display masked.
3. Adapter validates, reserves when supported, and redeems/captures idempotently.
4. Auto-capture occurs only after authoritative atomic reservation/redemption and exact brand/region/value/currency policy match.
5. Valid-but-unreservable, image-only without authoritative extraction, pending/unknown/mismatch/already-used/provider-error cases enter manual review.
6. Reconciliation resolves lost responses and local/provider divergence without double funding.

## `UC-05` — USDT, Zarinpal, and NOWPayments

**Requirements:** `USDT-001`–`USDT-003`, `IPG-001`, `IPG-002`, `PAY-002`, `PAY-003`

- **USDT:** lock a bounded, non-stale IRR rate, margin, exact fixed-decimal amount, BEP20 address/network, and expiry; accept unique TXID; verify network/destination/amount/confirmations/time; route mismatches to review.
- **Zarinpal:** create current REST request; treat return as navigation only; server-verify authority/amount/unit/order; idempotently store reference and reconcile.
- **NOWPayments:** create payment; verify canonical IPN signature before processing; re-query sensitive status; match IDs/amount/currencies/order; apply approved partial/over/under policy; reconcile duplicate/out-of-order events.

No branch provisions before authoritative capture.

## `UC-06` — Wallet top-up, payment, transfer, correction, and refund

**Requirements:** `WAL-001`–`WAL-005`

1. External top-up follows a normal Payment Intent; capture posts one balanced cash transaction.
2. Purchase/auto-renew atomically holds available eligible buckets, captures once on committed action, or releases once.
3. Transfer resolves a stable recipient, shows masked confirmation, validates status/identity/limits/fee/transferability, and atomically posts debit/credit.
4. Admin correction requires explicit permission, reason, preview/confirmation, optional dual approval, and a compensating transaction.
5. Refund is idempotent, cumulative-value-bounded, method-specific, bucket-preserving for wallet, and excludes card adjustment.
6. Reconciliation proves balance snapshots, global/transaction balance, expired holds, captures/refunds, no duplicate consumption, no negative balance, and no orphan entries.

**Failure:** unexplained mismatch suspends affected automation and alerts Owner as Critical.

## `UC-07` — Trial and custom plan

**Requirements:** `CAT-005`, `CAT-006`, `CAT-008`

- Custom plan enforces GB/day min/max/step, minimum price, eligibility, username policy, and price snapshot before payment and again before provisioning.
- Trial evaluates independently configured identity/membership/one-per-user/phone/capacity/fallback/abuse rules and creates a `trial` zero-cost order source without a fake payment.

## `UC-08` — Agent lifecycle and buying

**Requirements:** `AGT-001`–`AGT-006`

1. Eligible customer reads current cooperation terms and submits one active request.
2. Authorized reviewer claims and reviews only permission-visible risk/commercial information.
3. Approval atomically assigns profile/pricing and exposes the agent menu; rejection requires localized reason and applies cooldown/release policy.
4. Suspend/restore preserves history and financial/commercial records.
5. Single purchase uses snapshotted agent quote/gateway rules.
6. Bulk purchase creates one parent and one idempotent child per service; retries target failed children only and never debit/create successful children again.
7. Agent profile/report is date- and permission-scoped.

## `UC-09` — Manage a service

**Requirements:** `SVC-001`–`SVC-007`, `SVC-013`, `SVC-014`

1. Customer lists/searches owned services and sees cached panel data with a clear timestamp during outage.
2. System exposes only actions allowed by both offering policy and adapter capability.
3. Paid renewal/add-data/add-days/combined/change-plan/location/protocol actions traverse quote → order → payment → provisioning.
4. Free reset, refresh, state change, log clear, rotation, resend, or retirement follows audited policy and idempotent remote operation.
5. Rotation warns that old link stops working; resend does not rotate.
6. Auto-renew holds/captures wallet once under configured price-change behavior.
7. Notification thresholds send once per cycle and reset after qualifying renewal/reset.

## `UC-10` — Import, transfer, repair, and batch grant

**Requirements:** `SVC-008`–`SVC-012`

1. Import parses only registered service domains and invokes a known adapter endpoint—never the submitted URL.
2. Remote identity is verified, previewed, and uniquely attached with explicit `imported` source and no payment.
3. Ownership transfer is a separate authorized action with validation and before/after audit.
4. Repair compares local and remote state and proposes only allowlisted metadata corrections; finance is immutable.
5. Manual/complimentary service and batch grants use explicit sources, preview scope/operations, and one idempotency key/result per service.
6. Batch can pause/resume/cancel/retry failures, notify customers, and export results without reapplying successes.

## `UC-11` — Promotions and referrals

**Requirements:** `PRO-001`, `PRO-002`, `REF-001`, `ONB-002`

- Price calculation order is base → account/tier/agent override → one eligible discount → final → payment adjustment; every component is snapshotted.
- Discount use is reserved during payment, redeemed after capture, and released on failed/expired intent.
- Platform gift codes separately grant promotional credit, service, or discount, with keyed lookup and one-time full-code disclosure where applicable.
- Referral is first-entry-bound, locks after first successful purchase, prevents self/shared-verified-phone abuse, releases reward after the configured pending period, and reverses unreleased/refundable effects idempotently.

## `UC-12` — Support ticket

**Requirements:** `SUP-001`, `SUP-002`

1. Customer selects category, title/description, optional owned entity, and safe attachment; system issues unique tracking number.
2. Customer sees/replies/closes only own ticket and may reopen within the configured window (default 72 hours).
3. Authorized support staff claim/assign/transfer, prioritize, reply, add customer-invisible notes, use canned responses, and close with reason.
4. Delivery retry, SLA alert, rate limit, immutable message history, and rating complete the lifecycle.

## `UC-13` — Direct message and broadcast

**Requirements:** `COM-001`–`COM-003`

- Direct message resolves one customer, previews Telegram ID/masked profile, confirms, sends supported content/buttons, and retains an idempotent outbound attempt/result.
- Broadcast defines filters, estimates recipients, previews and test-sends to Owner, then starts now/scheduled with unique per-recipient state.
- Worker obeys Telegram rate limits and can pause/resume/cancel; transient failures retry boundedly while permanent failures stop.
- Edit/button/pin/unpin/delete jobs operate only where Telegram permits, test on Owner first for high-risk scope, store per-recipient result, and retry failures only.

## `UC-14` — Administrator control and sensitive approval

**Requirements:** `ACL-001`–`ACL-003`, `ADM-001`, `ADM-002`, `USR-003`, `WAL-005`

1. Administrator opens a permission-filtered menu; execution always recalculates roles plus user overrides with deny precedence.
2. Unified search resolves supported identifiers and masks each field based on separate view/reveal authority.
3. Sensitive action displays exact target/effect, requires reason and confirmation, then optional independent second approval by policy.
4. Ownership transfer, role/permission/secret changes, refund, wallet correction, restore/update, and large batch retain immutable actor/decision/before-after/correlation evidence.

## `UC-15` — Reporting and operations response

**Requirements:** `REP-001`–`REP-003`, `OPS-001`–`OPS-003`

1. Administrator selects a report, permitted dimensions, Tehran-displayed UTC-safe range, and output destination.
2. Financial measures derive from captured payments/ledger and state currency/unit/refund/adjustment/timezone definitions.
3. Exports are audited, expiring, and masked by minimum-necessary permission.
4. Operations Center presents queue/Scheduler/webhook/panel/provider/SMS/verification/backup/release/storage health and pending reconciliation/incidents.
5. Safe retry/reconcile actions require specific permission and are idempotent; alerts are persisted, deduplicated, retried, acknowledged, and resolved.

## `UC-16` — Install, back up, restore, update, and roll back

**Requirements:** `INS-001`, `BAK-001`, `BAK-002`, `UPD-001`, `RUN-001`–`RUN-006`, `SEC-010`

- **Install:** verified bootstrap stages a release; HTTPS one-time installer preflights both PHP runtimes, DB/Redis/filesystem/OLS/Telegram/SMS/channels, accepts secrets safely, writes restrictive `.env`, migrates/seeds, creates Owner/webhook, checks health, reports without secrets, and permanently locks.
- **Backup:** scheduled/pre-update run creates consistent archive/manifest, checksum, authenticated encryption, retention, and optional acknowledged ≤45 MB Telegram parts; incomplete transfer is failure.
- **Restore:** authorized confirmation, maintenance, safety backup, integrity/decryption/compatibility preflight and optional isolated restore precede real restore; smoke and financial/service reconciliation precede resume.
- **Update:** authenticate Owner, verify signed package and compatibility, check active financial work, back up, stage immutable release, install locked dependencies as `www`, drain, migrate, smoke, atomically switch, restart/verify, and report.
- **Rollback:** switch code only when schema compatible; otherwise use explicit tested migration reversal or pre-update restore with stated data-loss window.

## Cross-journey acceptance rules

- Customer-visible copy is complete Persian with English fallback and validated placeholders/markup.
- Amounts are integer IRR internally and explicitly displayed as Toman; crypto is fixed precision.
- Browser/Telegram/provider payloads are untrusted; permissions, state, identity, and eligibility are rechecked at execution.
- Secrets, OTPs, full PAN/national ID/gift code/subscription URL, private evidence, and raw provider/Telegram payloads never enter normal logs or alerts.
- No journey is accepted without mapped automated tests, command/result, and retained evidence. All are currently `not-started`.
