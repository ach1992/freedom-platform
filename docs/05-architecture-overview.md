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

## Critical MariaDB authority-surface lifecycle contract

Correctness-critical MariaDB authority surfaces must explicitly disposition seven lifecycle concerns before they are treated as reusable architecture: complete-enough metadata evidence, install/upgrade fencing, interrupted apply/re-entry, rollback preflight before destructive DDL, external-DDL/TOCTOU handling, incoming/outgoing dependency checks, and postflight readiness. The strategies do not need identical schema shapes: a surface may use explicit advisory/reference fencing or MariaDB metadata-lock fail-closed behavior, but it must name the strategy and bind it to executable/runtime evidence.

`scripts/ci/architecture-boundaries.php` is the machine-readable registry and `CriticalMariaDbLifecycleContractChecker` validates it from PHP tokens. Every registered surface must map all seven rules to real declared PHP methods/functions; comments or string mentions cannot satisfy evidence. CI currently proves the contract against both the Telegram outbound-delivery authority and the Service operational authority. Adding another critical authority surface therefore requires extending the same rule map instead of copying one migration's implementation shape.

## Durable correctness boundaries

MariaDB is the final authority for:

- uniqueness and idempotency;
- guarded state transitions;
- financial integrity;
- immutable history;
- capacity/ownership conflicts;
- replay/conflict detection.

Redis locks may reduce duplicate work but never replace database constraints or transactions.

Every migration-created durable table has one exact feature owner or reviewed infrastructure classification in `scripts/ci/architecture-boundaries.php`; CI verifies that the map is complete and not stale across literal `Schema::create(...)`, raw `CREATE TABLE` in migration PHP or migration support SQL, and bounded `Blueprint(...)->create()` / helper-created table paths when every table callsite can be proven literal. Unresolved or non-literal creation fails closed, and aliasing the migration `Schema` or `Blueprint` primitives is rejected so discovery cannot be bypassed by syntax variation. Table renames also fail closed until the post-rename ownership lifecycle is explicitly modeled. The same owner map rejects cross-owner mutations and any runtime mutation of an unmapped literal table. Runtime persistence must remain statically attributable: literal table chains and statically bounded literal table sets are allowed when ownership matches, while unbounded dynamic mutations, deferred mutable Query Builder handles, unattributable `fromRaw`/`fromSub` mutation sources, the installed Laravel Query Builder write surface, raw `Connection` writes, non-literal or unsupported raw `Connection` reads, runtime schema mutation/escape APIs, opaque raw DML/DDL, direct PDO access, aliased `DB` facade access, and Eloquent persistence under `app/Modules` or `app/Shared` fail closed when attribution cannot be proven. Raw `Connection` reads are normally restricted to one literal read-only `SELECT`; the reviewed non-`SELECT` authority introspections are the exact literal `SHOW GRANTS FOR CURRENT_USER` in `TelegramDeliveryForeignKeyMetadataAttestor` and `TelegramDeliveryLifecycleDatabaseAuthority`; the architecture checker pins each exception to the exact Telegram authority source paths and exact statement. Read-only schema introspection such as `hasTable()` remains allowed. Only literal `SET @name = ...` user-variable statements used for connection-scoped MariaDB session authority are treated as non-durable; broader `SET` forms such as global/system-variable changes fail closed. The historical Agent pricing trigger helper is the sole exact migration-only raw trigger-DDL helper and remains tracked under #183; new runtime raw DDL is not permitted by that exception. Cross-cutting `audit_logs` is a shared append-only sink: Application code may append records, but update/delete-style mutations remain forbidden. The historical cross-owner runtime seams tracked by #188 have been removed and the repository's `persistence_exceptions` registry is empty. Cross-module writes must go through the owning module's narrow boundary while preserving the caller's transaction/lock authority; reintroducing a persistence exception is therefore a new reviewed architecture regression rather than a continuation of legacy debt. The checker still rejects wildcards, duplicate entries, and stale/unused exceptions if the mechanism is ever deliberately exercised again.

Critical authority surfaces that require globally complete MariaDB foreign-key visibility must not infer completeness from the ordinary application's `KEY_COLUMN_USAGE` view alone. The generic outbound Telegram delivery authority uses a distinct `telegram_metadata` connection backed by a separate database principal whose complete effective grant set must resolve to only global `PROCESS`/`USAGE`; any grant option, role/PUBLIC grant, routine `EXECUTE`, schema/table/column privilege, or other authority fails closed. The ordinary runtime principal must remain unable to read `information_schema.INNODB_SYS_FOREIGN`, and there is no fallback to the runtime principal. Runtime and metadata connections must prove they reach the same server with a non-empty `@@server_uid`; the Telegram metadata authority therefore requires MariaDB `>=10.11.9`, where that unique server identity is available, and missing/older/ambiguous identity fails closed rather than falling back to hostname/port/server-id/version equivalence. Hidden incoming and outgoing relationships are checked against the complete global InnoDB FK inventory. Database account/grant creation remains an external protected deployment operation rather than an application migration side effect.

Critical rollback must also preserve protection across both runtime transactions and dependency-sensitive DDL. For the Telegram delivery authority, every queue/effect transaction acquires a shared lifecycle lock on the singleton capability row before taking operation/Outbox row locks, and the terminal queue/effect database triggers independently take the same shared row lock before authorized DML. Lifecycle activation/deactivation is authorized by a separate deployment-only MariaDB account with the fixed username `telegram_lifecycle`; its complete effective grant set must be only global `USAGE` plus `SELECT, UPDATE` on the exact application schema, with no `INSERT`, `DELETE`, DDL, `PROCESS`, routine/role/PUBLIC/proxy/grant-option or other privilege. Runtime and metadata principals must be distinct from it and it must prove the same `@@server_uid`. Its password is injected only into the protected migration/decommission process and is not an installer-persisted runtime credential. The advisory `GET_LOCK` remains serialization only: the capability trigger additionally requires `SUBSTRING_INDEX(USER(),'@',1) = 'telegram_lifecycle'`, the real capability secret and exact lock ownership, so ordinary runtime credentials cannot activate/deactivate even if they successfully acquire the deterministic lock.

Generic outbound Telegram delivery is a persistable non-restricted presentation authority, not a generic string sink. New presentation objects are created only from `NonRestrictedTelegramPresentationSource` through `NonRestrictedTelegramPresentationFactory`, and `scripts/ci/architecture-boundaries.php` keeps an exact `telegram_non_restricted_presentation_sources` allowlist for production source paths permitted to construct/consume that generic boundary. Runtime queue authority does not trust PHP-private capability objects: the queue and database-authority boundary independently inspect the engine-produced call-site path and require the first repository caller outside the Telegram authority internals to be an exact reviewed source. A deeper reviewed frame cannot bless an unreviewed helper/adaptation layer. The dedicated provenance checker rejects recognizable dynamic resolver/callable escape forms only when Telegram provenance context is present; unrelated Reflection/container/callable use elsewhere in the repository is not globally prohibited. Cross-module/Shared/route use of the generic queue/presentation types remains forbidden, so owners of RESTRICTED values such as Provisioning Service delivery cannot adapt those values into `telegram_delivery_operations`; they must retain the protected/reference delivery path. Adding an allowlisted source is therefore a data-classification review action, not routine wiring.

Rollback acquires the installation lock on the dedicated lifecycle connection, then that same lifecycle session takes the capability row `FOR UPDATE` to drain and exclude runtime authority and performs locking current reads for operation/`telegram.delivery.requested` Outbox evidence. Only when both are empty does it persist capability inactive (`schema_version=0`, `activated_at=NULL`). If an already-entered producer commits durable authority while rollback is waiting, rollback refuses and the capability transaction rolls back to active; after the inactive fence commits, new producers fail closed before they can create authority. Operator quiescence remains required release defense-in-depth but is not the mechanical exclusion boundary.

After that persistent runtime fence is established, dependency-sensitive DDL uses a MariaDB reference-exclusion barrier for each authority table before its final no-dependency attestation and `DROP`. The migration takes a table `WRITE` lock with autocommit disabled, atomically removes every parent-reference index (and the operations `AUTO_INCREMENT` attribute required by its primary key), and marks the resulting rollback-fenced shape. If an incoming/outgoing FK raced before this barrier, MariaDB rejects the atomic index-removal `ALTER` and leaves the original table shape intact. Once the barrier succeeds, a new FK cannot reference the now-indexless parent even when the competing session disables `FOREIGN_KEY_CHECKS`, while the retained `WRITE` lock prevents a privileged competing session from restoring a parent index or otherwise altering that table before destructive `DROP`. Global FK metadata is attested after the barrier and re-attested after the adversarial window. `telegram_delivery_operations` is dropped before the capability table, each authority table keeps its own triggers until the table itself is successfully dropped, and shared Outbox guards are removed only after both authority tables are gone. A crash after reference fencing but before `DROP` leaves an inactive, guard-preserving marker state that `down()` or safe `up()` reset can independently re-attest and resume. Exact rollback-fenced full-surface re-entry may reactivate under the installation lock only when there is still no durable authority; unrecognized partial surfaces and orphaned durable evidence fail closed. `GET_LOCK` still serializes only cooperative migration runners; the table lock plus structural no-reference-index barrier is the mechanical exclusion boundary for non-cooperating parent DDL/FK races.

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

Runtime readiness is a fail-closed deployment contract, not only a reachability probe. The shared `RuntimeHealthProbe` verifies the supported MariaDB family/version/server identity and connection charset/collation/strict-mode assumptions, authenticated Redis for active queue/cache dependencies, Redis queue `after_commit`, and `retry_after` above every reviewed Supervisor worker timeout. The guarded release-switch invokes the same `health:check --critical` path after activation so deployment postflight cannot drift into a separate shell-only rule set.

## Source of implementation truth

Current implementation boundaries come from source, migrations, tests, and live GitHub PR/CI state. Do not add module status tables or implementation progress to this document.
