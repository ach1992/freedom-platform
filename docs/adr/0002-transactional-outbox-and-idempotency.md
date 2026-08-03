# ADR 0002: Transactional Outbox and Database-Backed Idempotency

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

Database commits cannot atomically include Telegram, queue, panel, or payment-provider calls. Webhooks, jobs, and callbacks are delivered at least once, and workers may crash after either local or remote effects.

## Decision

Insert an outbox record in the same MariaDB transaction as the business state change. Consumers claim and deliver records with bounded retry, but every downstream effect also has a stable scoped idempotency key and database uniqueness barrier. Redis locks coordinate workers only; correctness survives lock expiry or Redis loss.

External mutating operations return definitive, retryable, or uncertain outcomes. After uncertainty, query/discover by provider idempotency ID or deterministic remote identity before repeating. Outbox “published” status does not assert exactly-once external delivery.

## Consequences

- Committed payment/provisioning intent is not lost when queue publication or Telegram delivery fails.
- Duplicate delivery is expected and harmless only when every consumer is idempotent.
- Outbox lag, attempts, dead letters, and heartbeats require monitoring and reconciliation.
- Payloads must be minimal and secret-free; store references rather than Restricted values.
- Cleanup cannot remove records still needed for deduplication within provider replay/financial dispute windows.

## Rejected alternatives

- Publish after commit without outbox: crash window loses the side effect.
- Redis lock as duplicate barrier: lease expiry and Redis failure can permit duplicates.
- “Exactly once” queue claim: transport semantics do not remove consumer/provider idempotency requirements.
