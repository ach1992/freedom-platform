# Freedom Platform

Freedom Platform is a Telegram-first commerce and lifecycle-management platform for VPN/proxy subscription businesses.

It is designed to support customers, agents/resellers, administrators, product catalog, orders, payments, wallet accounting, service provisioning, renewals, trials, support, content, broadcasts, reporting, and operational workflows from one system.

## Product shape

Version 1 is designed as a Laravel modular monolith with Telegram as the primary product surface. The architecture keeps financial, provisioning, authorization, and provider behavior behind explicit application/domain boundaries so additional interfaces and adapters can be added without redefining core business rules.

The Version 1 product scope includes:

- Persian-first Telegram UX with English fallback;
- customer, agent/reseller, and administrator journeys;
- wallet and multiple payment-method integrations;
- Marzban and PasarGuard service-panel adapters;
- transaction-safe and idempotent financial/provisioning effects;
- Redis-backed queues, cache, throttling, and coordination;
- MariaDB-backed durable business state;
- controlled backup, restore, update, rollback, and operational tooling.

## Runtime baseline

- PHP 8.4
- Laravel 13.x
- MariaDB 10.11 compatible baseline
- Redis with authentication
- Telegram Bot API
- aaPanel / OpenLiteSpeed deployment target

## Getting started

For development setup and contribution workflow, see [`CONTRIBUTING.md`](CONTRIBUTING.md).

For the technical documentation index, architecture, security, testing, execution infrastructure, and operations guidance, see [`docs/index.md`](docs/index.md).

Repository and AI-agent working rules are in [`AGENTS.md`](AGENTS.md).

Production use should be based on an accepted release and its release/deployment instructions rather than an arbitrary development commit.

## Security

Never commit or publish production credentials, private provider payloads, customer secrets, payment instruments, subscription URLs, or other sensitive production data.

## License

Proprietary. All rights reserved.
