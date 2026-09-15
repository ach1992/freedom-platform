# ADR 0004: Explicit External-Provider Boundary

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

Marzban, PasarGuard, payment gateways, bank/gift verification services, SMS, rate APIs, blockchain sources, and Telegram have different semantics, versions, failure modes, and authority guarantees. Provider response models must not leak into Order, Ledger, or Provisioning rules.

## Decision

Define capability-reporting ports with immutable normalized DTOs and explicit units. Provider adapters own authentication, request/response mapping, TLS, timeout/rate handling, signature verification, and error classification. Application Services alone perform local state transitions. Generic REST adapters permit only versioned declarative mappings and allowlisted transforms through a centralized SSRF-safe client.

Each activation requires a dated contract note, fake/contract tests, current official documentation review, health check, safe secret configuration, and explicit enablement. Unknown/pending/validation-only results never become payment capture. Provider-specific behavior is represented by capabilities rather than false semantic equivalence.

### Generic REST bank field-mapping subset

The card-to-card Generic REST adapter evaluates field mappings relative to each decoded transaction object. Existing direct keys keep their original meaning and use the existing `[A-Za-z_][A-Za-z0-9_-]{0,63}` grammar.

Nested mappings use only this bounded JSON-path subset:

- a path starts with `$` and a dot-key segment, for example `$.payment.transaction.id`;
- object keys use the same safe direct-key grammar;
- zero-based array traversal uses a literal bounded index, for example `$.items[0].amount`;
- paths are at most 256 bytes, at most 12 traversal segments, and array indices are limited to `0..255`;
- terminal path results must be a string or integer and remain subject to the canonical field's stricter validation and size rules; a path-terminal string is additionally limited to 4096 bytes before field normalization;
- a configured nested path that is missing, resolves through the wrong container type, or ends in `null`, boolean, float, object, or array fails closed before provider evidence ingestion;
- wildcards, recursive descent, quoted bracket keys, slices, filters, predicates, scripts, functions, expressions, interpolation, templates, `eval`, SQL, shell syntax, and caller-supplied executable behavior are not part of the grammar and are rejected during configuration validation.

The response-envelope keys (`transactionsKey`, `nextCursorKey`, and `cursorParameter`) remain direct keys. JSON-path mapping only selects fields from an already decoded transaction response; it does not add a second evidence or capture authority and does not alter the adapter's HTTPS, SSRF/DNS-pinning, redirect, timeout, content-type, or response-size boundary.

## Consequences

- New providers can be added without modifying core Order/Ledger/Provisioning logic.
- DTO mapping and contract suites add code but isolate change and make uncertainty explicit.
- Live behavior cannot be certified from documentation/fakes alone; installed/versioned systems require contract evidence.
- A compromised authoritative provider remains a residual business risk mitigated by local matching, limits, manual review, and reconciliation.

## Rejected alternatives

- Call provider SDKs directly from controllers/jobs: couples workflows and bypasses consistent state/security rules.
- One overly generic provider model with arbitrary scripts: creates injection/SSRF risk and hides semantic differences.
