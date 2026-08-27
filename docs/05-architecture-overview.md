# Architecture

This document defines durable architecture and correctness boundaries. It does not track implementation status.

## Baseline

- PHP 8.4 / Laravel 13.x
- MariaDB as durable system of record
- authenticated Redis for queue/cache/rate-limit/coordination only
- Telegram-first presentation with narrowly scoped HTTP/browser endpoints
- modular monolith
- atomic versioned releases with shared runtime storage

## Module layering

A feature module uses:

```text
app/Modules/<Module>/
├── Domain/
├── Application/
│   └── Contracts/
├── Infrastructure/
└── Presentation/
```

Rules:

- Domain does not depend on Laravel/framework infrastructure.
- Application owns use-case orchestration and declared ports.
- Infrastructure implements persistence/provider/framework ports.
- Presentation validates transport input and calls Application services.
- Cross-module mutations go through explicit Application boundaries.
- A module must not import another module's Infrastructure implementation.
- Shared primitives live under `App\Shared` and must not depend on feature modules.

## Durable correctness boundaries

MariaDB is the final authority for:

- uniqueness and idempotency;
- guarded state transitions;
- financial integrity;
- immutable history;
- capacity/ownership conflicts;
- replay/conflict detection.

Redis locks may reduce duplicate work but never replace database constraints or transactions.

Every migration-created durable table has one exact feature owner or reviewed infrastructure classification in `scripts/ci/architecture-boundaries.php`; CI verifies that the map is complete and not stale across literal `Schema::create(...)`, raw `CREATE TABLE` in migration PHP or migration support SQL, and bounded `Blueprint(...)->create()` / helper-created table paths when every table callsite can be proven literal. Unresolved or non-literal creation fails closed, and aliasing the migration `Schema` or `Blueprint` primitives is rejected so discovery cannot be bypassed by syntax variation. Table renames also fail closed until the post-rename ownership lifecycle is explicitly modeled. The same owner map rejects cross-owner mutations and any runtime mutation of an unmapped literal table. Runtime persistence must remain statically attributable: literal table chains and statically bounded literal table sets are allowed when ownership matches, while unbounded dynamic mutations, deferred mutable Query Builder handles, unattributable `fromRaw`/`fromSub` mutation sources, the installed Laravel Query Builder write surface, raw `Connection` writes, non-literal or non-`SELECT` raw `Connection` reads, runtime schema mutation/escape APIs, opaque raw DML/DDL, direct PDO access, aliased `DB` facade access, and Eloquent persistence under `app/Modules` or `app/Shared` fail closed when attribution cannot be proven. Read-only schema introspection such as `hasTable()` remains allowed. Only literal `SET @name = ...` user-variable statements used for connection-scoped MariaDB session authority are treated as non-durable; broader `SET` forms such as global/system-variable changes fail closed. The historical Agent pricing trigger helper is the sole exact migration-only raw trigger-DDL helper and remains tracked under #183; new runtime raw DDL is not permitted by that exception. Cross-cutting `audit_logs` is a shared append-only sink: Application code may append records, but update/delete-style mutations remain forbidden. Pre-existing cross-owner runtime seams may be grandfathered only by exact `source-path|table` entries tracked for removal in #188; wildcards are not accepted and stale/unused exceptions fail CI.

## Pricing transaction rules

- Quote/agent-pricing commands that X-lock the shared pricing roots acquire the subject `users` row before `plan_offerings`, then `agent_profiles`, then the stable `agent_pricing_profiles` root; immutable agent-pricing profile/rule versions are selected only after that stable root barrier, and nested callers already holding the subject/offering must not acquire the shared pricing-profile root before that same offering.

## Financial rules

- fiat is integer IRR; crypto uses fixed-precision decimal;
- browser/customer evidence never proves capture;
- only authoritative captured settlement may authorize paid provisioning;
- ledger postings are balanced and append-only;
- corrections/refunds/reversals are new compensating records, never history edits;
- one external transaction cannot fund multiple accepted payments;
- one paid order item cannot create multiple active remote identities;
- financial commands that X-lock multiple `ledger_accounts` must determine the complete participant account-ID set before the first account X-lock, deduplicate it, and acquire rows in ascending numeric `ledger_accounts.id` order; callers must not pre-lock an incomplete participant subset in an order that can conflict with that complete canonical set.

## External effects

External mutations must be recoverable and idempotent:

1. persist local intent/operation identity;
2. perform bounded external call;
3. persist definitive/retryable/uncertain outcome;
4. if outcome is uncertain, perform authoritative lookup/discovery before retrying a mutation;
5. adopt an exact existing remote match, reject conflict, or route to review.

Do not hold a long MariaDB transaction open around remote network calls.

## Security boundaries

- execution-time authorization with default deny;
- provider/Telegram callbacks authenticated before business processing;
- configurable outbound endpoints use HTTPS and SSRF/rebinding protections;
- TLS certificate/hostname verification cannot be disabled;
- secrets and restricted data are referenced/encrypted, masked, and redacted from logs/jobs/evidence;
- private media and subscription material remain outside public storage.

See `07-security-threat-model.md` and `08-data-classification.md`.

## Deployment shape

Target layout:

```text
<root>/
├── releases/<version>/
├── shared/
└── current -> releases/<version>
```

Only `current/public` is web-exposed. CLI and LSPHP PHP 8.4 runtimes are independently verified. One Scheduler Cron drives scheduled work; supervised workers process isolated queues.

## Source of implementation truth

Current implementation boundaries come from source, migrations, tests, and live GitHub PR/CI state. Do not add module status tables or implementation progress to this document.
