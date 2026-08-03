# Module boundaries

Each business module uses four optional layers: `Domain`, `Application`,
`Infrastructure`, and `Presentation`. A layer is created only when it contains
real behavior; empty DDD ceremony is prohibited.

Dependency direction:

1. `Domain` depends only on PHP and `App\Shared\Domain`.
2. `Application` depends on its Domain layer and shared application contracts.
3. `Infrastructure` implements contracts and may depend on Laravel, persistence,
   queues, HTTP clients, and external SDKs.
4. `Presentation` translates Telegram or HTTP input into Application commands
   and queries. It never implements pricing, payment, ledger, authorization, or
   provisioning rules.
5. Cross-module writes use an explicit Application service. Database tables are
   not a private integration API.

Canonical modules and dependency rules are maintained in
[`docs/05-architecture-overview.md`](../../docs/05-architecture-overview.md).
