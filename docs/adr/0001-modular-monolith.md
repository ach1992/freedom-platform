# ADR 0001: Laravel Modular Monolith

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

The platform has many domains and external integrations but one operational owner, one target server, shared financial invariants, and transactionally coupled order/payment/ledger state. A future web UI must reuse business behavior.

## Decision

Build one Laravel 13 deployable modular monolith. Each feature module separates Domain, Application, Infrastructure, and Presentation concerns without forcing ceremony where simple code suffices. Telegram/HTTP are adapters. Cross-module mutation uses declared Application Services; modules do not write another module's tables or import its Infrastructure models. External systems are accessed through ports/adapters. MariaDB transactions may cover multiple modules when a synchronous invariant requires it.

## Consequences

- Financial and order transitions can remain atomic without distributed transactions.
- One release, scheduler, worker fleet, and observability surface simplify aaPanel/OpenLiteSpeed operation.
- Module dependency rules and architecture tests are necessary to prevent a “big ball of mud.”
- Independent scaling is queue/worker based; a future service extraction requires an explicit contract and data ownership migration.
- A future web UI calls the same Application Services and cannot duplicate domain rules.

## Rejected alternatives

- Microservices: premature operational/distributed-consistency cost and weaker financial atomicity for this deployment.
- Controller/Eloquent-centric Laravel application: fast initially but makes Telegram, future web UI, provider replacement, and invariant testing unsafe.
