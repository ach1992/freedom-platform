# PAY-001 v2 payment-method eligibility and routing foundation

## Scope

This bounded foundation evaluates a server-owned, unexpired `BUY-002` Quote and produces an immutable routing snapshot. It does not create a purchase payment authority, Payment Intent, provider request, wallet/ledger entry, Order, provisioning, Service effect, or customer-facing checkout flow.

## Inputs and trust boundary

The evaluator accepts only an idempotency key, the authenticated Quote owner and a Quote public ID. It locks and derives these facts from local storage:

- Quote owner, immutable IRR final amount, currency, expiry and Quote configuration hash;
- current user account state/type, customer tier/tags, identity status and agent state;
- current offering code, product ID and sales-server ID;
- current versioned payment-method/rule configuration and local persisted health observation.

For normal customer Quotes, the action is fixed to `purchase`. No client action, amount, history, limit, health, provider, or override fact is accepted. Contact-vs-OTP provenance, purchase history/spend and daily-payment-limit facts are intentionally unavailable in this increment. Any rule requiring one of those facts does not match and records its safe unavailable reason; it is never inferred from `wallet_top_up`.

## Deterministic policy semantics

1. A disabled/maintenance method, missing/expired/unhealthy health observation, inactive customer, or suspended agent is a hard block.
2. A matching per-user `deny` wins. A matching per-user `allow` can bypass only normal commercial rules after hard-block evaluation.
3. Among matching normal rules, the greatest priority wins. Equal greatest `allow` and `deny` is `ambiguous_rule_set` and excludes the method.
4. If no normal rule matches, an otherwise healthy enabled method is eligible.
5. Eligible methods are ordered by `display_priority`, then `method_code`.
6. An empty route is valid and fail-closed.

Rules support account type, tier, normalized tags, IRR range, offering/product/server scope, identity/agent state, UTC time window and explicit per-user override. Multiple tag conditions are an all-of match. The snapshot is decision evidence only; a later purchase authority must re-evaluate and establish settlement independently.

## Persistence and integrity

Migration `2026_08_10_003800_create_payment_method_eligibility_foundation.php` introduces fresh append-only method/rule versions, normalized rule-tag rows, local health observations, immutable replay-safe decisions and immutable selected-method rows.

- Rule/method/health changes require `payment_providers.manage`, an active administrator lock and in-transaction reauthorization.
- All change records include idempotency/request hashes, administrator, reason, correlation ID and canonical safe snapshot hashes.
- Decision replay returns the original immutable decision; key reuse with a different Quote/actor fails.
- Foreign keys, uniqueness, database checks, source-Quote guards, sequential version triggers and immutable update/delete triggers are final correctness barriers.
- Health remains a local observation only. This increment performs no provider call.

## Requirement coverage

| Requirement | Boundary |
|---|---|
| `PAY-001` | deterministic eligibility/routing policy, health precedence and immutable decision snapshot |
| `BUY-002` | valid immutable Quote ownership, IRR/currency/expiry binding |
| `ACL-002`, `SEC-002` | execution-time administrator authorization and Quote-owner authorization |
| `DAT-002`–`DAT-004` | integer IRR, relational constraints, append-only decision/audit records |
| `SEC-003` | secret-safe, minimized snapshots; no provider credential or identity value |
| `QUA-001`, `QUA-003`, `QUA-004` | focused policy and MariaDB feature coverage |

## Explicit nonclaims

This artifact does not claim completion of purchase settlement, payment capture, historical purchase/limit eligibility data, live provider health, external provider compatibility, C2C, gift-card, USDT transaction verification, Zarinpal, NOWPayments, Order/provisioning/Service integration, or aaPanel acceptance.
