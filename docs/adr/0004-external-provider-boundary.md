# ADR 0004: Explicit External-Provider Boundary

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

Marzban, PasarGuard, payment gateways, bank/gift verification services, SMS, rate APIs, blockchain sources, and Telegram have different semantics, versions, failure modes, and authority guarantees. Provider response models must not leak into Order, Ledger, or Provisioning rules.

## Decision

Define capability-reporting ports with immutable normalized DTOs and explicit units. Provider adapters own authentication, request/response mapping, TLS, timeout/rate handling, signature verification, and error classification. Application Services alone perform local state transitions. Generic REST adapters permit only versioned declarative mappings and allowlisted transforms through a centralized SSRF-safe client.

Each activation requires a dated contract note, fake/contract tests, current official documentation review, health check, safe secret configuration, and explicit enablement. Unknown/pending/validation-only results never become payment capture. Provider-specific behavior is represented by capabilities rather than false semantic equivalence.

## Consequences

- New providers can be added without modifying core Order/Ledger/Provisioning logic.
- DTO mapping and contract suites add code but isolate change and make uncertainty explicit.
- Live behavior cannot be certified from documentation/fakes alone; installed/versioned systems require contract evidence.
- A compromised authoritative provider remains a residual business risk mitigated by local matching, limits, manual review, and reconciliation.

## Rejected alternatives

- Call provider SDKs directly from controllers/jobs: couples workflows and bypasses consistent state/security rules.
- One overly generic provider model with arbitrary scripts: creates injection/SSRF risk and hides semantic differences.
