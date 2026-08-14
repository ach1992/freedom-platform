# Self-Contained Master Execution Prompt
## Production-Grade Telegram VPN Service Commerce and Management Platform

**Document version:** 1.0.0  
**Reference date:** 2026-08-03  
**Canonical language:** English  
**Target release:** `1.0.0` production release  
**Product UI language:** Persian by default, multilingual-ready  
**Execution model:** ChatGPT Work, a coordinated multi-agent engineering team, or a human software team  
**Authoritative rule:** This document is the normative Version 1 product, security, correctness, runtime, integration, testing, deployment, and final-acceptance specification. Implement its capabilities, invariants, and delivery boundaries; do not invent business integrations or silently omit a defined requirement. Repository execution mechanics and live delivery state are governed by `AGENTS.md`, `CONTRIBUTING.md`, the canonical engineering references, and live GitHub.
**Repository governance reconciliation:** `2026-08-11` — process-only instructions that required duplicate status, traceability, handoff, or per-task evidence artifacts are superseded; Version 1 product/security/correctness scope is unchanged.

---

# 0. Product Delivery Command

Complete the production-ready `1.0.0` product defined by this specification from the repository's current accepted state. Do not restart the project, recreate retired planning artifacts, or treat historical process instructions as current project state.

## 0.1 Repository execution model

1. Read the owning requirements and relevant canonical references before changing product behavior.
2. Use the existing stable requirement IDs in `docs/01-authoritative-requirements.md`; do not create a second requirement ledger or mutable traceability matrix. Link implementation work to requirement IDs through GitHub Issues, PRs, code/tests where useful, and review history.
3. Use live GitHub as the delivery system: Program Issue `#3` -> active phase Issue -> bounded task Issue -> PR/checks. Every independently reviewable task has one owning Issue that records its authority, outcome, dependencies, bounded scope, acceptance criteria, validation strategy, and material risk. Dynamic priority, status, blockers, decisions, branches, SHAs, reviews, and CI results do not belong in repository status files or Chat.
4. Work in outcome-based phases. A phase closes when its GitHub exit criteria and applicable product/security/financial/operational validation are satisfied, not when a ceremonial report is produced.
5. Ask the Owner only for decisions that cannot safely be derived from the specification/current repository, or that require real credentials/accounts, privileged infrastructure action, business/legal policy, production rollout, irreversible action, or explicit High/Critical approval.
6. Never place credentials or sensitive customer/provider/payment data in Chat, commits, fixtures, screenshots, logs, PR/Issue text, or retained evidence.
7. Testing claims must be reviewable from focused commands/results and applicable CI/checks. Run risk-proportionate checks only when they can inform a decision; keep draft PRs quiet, reuse valid green evidence when the resulting tree is materially unchanged, and never weaken or rerun deterministic checks merely to manufacture a pass. Do not create a per-task evidence document or artifact merely to restate a successful workflow run.
8. Update an existing canonical document only when a durable product, architecture, security, testing, compatibility, or operations rule changes. Keep the repository clean: give each durable concept one canonical home, keep dynamic delivery history in Git/GitHub, and remove obsolete coordination artifacts rather than preserving parallel accounts of state.
9. Production acceptance does not mean absolute zero defects. It requires no known unresolved Critical/High release defect, all mandatory acceptance checks, proven financial/authorization/idempotency/restore/release invariants where applicable, and explicit treatment of remaining accepted risks.
10. Keep development fast without lowering safeguards: prefer the smallest safe, cohesive, independently reviewable change; use CI tiers and focused tests in proportion to change risk; avoid work, checks, or documents that do not improve implementation, review, recovery, or release safety.
11. Keep a Laravel modular monolith with the minimum infrastructure needed for reliable operation. Optimize boundaries for future features/adapters/UI surfaces; do not refactor solely because a file is large or split cohesive behavior merely to reduce line count.
12. Never provision a paid service before authoritative payment settlement, never create two services for one paid item, and never allow duplicate callbacks/webhooks/retries/operator actions to create a second financial or irreversible remote effect.
13. Stop acceptance when a financial invariant, authorization boundary, restore/release integrity check, or provisioning idempotency requirement fails.

## 0.2 State retention and handover

A new human developer or AI agent with no Chat history must be able to continue from `README.md`, `AGENTS.md`, `CONTRIBUTING.md`, Program Issue `#3`, Draft integration PR `#6`, the active task, and only the relevant canonical references. The GitHub Program/phase/task/PR chain owns live progress and decisions; recovery must not depend on private context, a previous operator, or a synchronized status document.

Do not create or restore `PROJECT_STATUS.md`, execution ledgers, mutable traceability matrices, per-task handoff files, per-task risk/evidence reports, or phase evidence directories merely for coordination. Git/GitHub/CI already preserve that history. Release-candidate/release records may be retained only when they have a real future operational or acceptance consumer.
---

# 1. Project Mission

Build a secure, maintainable, extensible Telegram-based platform for selling and managing VPN/proxy service subscriptions. The system must handle customers, agents/resellers, administrators, product catalog, server and panel integrations, orders, multiple payment methods, wallet accounting, provisioning, renewals, add-ons, trials, support tickets, broadcasts, referrals, discounts, backups, installation, updates, monitoring, and auditability.

Build the application as a new production product with a fresh schema, explicit domain models, and deterministic installation.

## 1.1 Required final result

The final release must include:

- complete Laravel source code;
- production-ready Telegram bot;
- modular business architecture;
- MariaDB migrations and seeders;
- Redis-backed queue, cache, and distributed locks;
- one scheduler Cron entry;
- queue worker management configuration;
- Marzban adapter;
- PasarGuard adapter;
- all payment methods specified in this document;
- manual and automatic card-to-card verification with runnable Fake and Generic REST provider implementations;
- manual and automatic gift-card verification with runnable Fake and Generic REST provider implementations;
- immutable wallet ledger;
- granular multi-role administrator permissions;
- customer and agent policies;
- localization and editable content system;
- support tickets;
- broadcast campaign lifecycle management;
- structured logging, alerts, audit logs, and Operations Center;
- encrypted backup and tested restore;
- secure browser-based installer;
- secure updater with rollback;
- full automated test suite;
- deployment instructions for aaPanel and OpenLiteSpeed;
- final installation package and checksum;
- final Feature Coverage, Security, Test, Restore, and Release reports.

## 1.2 Version 1 delivery boundary

Version `1.0.0` shall deliver the following product shape:

- all customer, agent/reseller, support, finance, technical, sales/content, and Owner operations are available through Telegram;
- browser routes are limited to secure installation, update, restore, health, and provider callback endpoints;
- the service-panel integrations are Marzban and PasarGuard, both behind a common adapter contract;
- the payment methods are internal wallet, card-to-card, gift card, direct USDT on BEP20, Zarinpal, and NOWPayments;
- every Payment Intent is settled by one payment method; wallet is treated as its own complete payment method;
- custom buttons and provider mappings are declarative, allowlisted, validated configuration;
- automatic financial capture occurs only from authoritative, authenticated, uniquely identifiable provider evidence; every ambiguous result enters manual review;
- the architecture remains ready for an additional user interface, payment provider, rate provider, SMS provider, storage destination, or service-panel adapter without changing core Order, Ledger, or Provisioning rules.

---

# 2. Target Infrastructure and Runtime

## 2.1 Production environment

Design and document deployment for:

- Ubuntu 22.04 LTS;
- aaPanel;
- OpenLiteSpeed;
- PHP 8.4 CLI at `/www/server/php/84/bin/php`;
- PHP 8.4 LSPHP at `/usr/local/lsws/lsphp84/bin/lsphp`;
- Composer at `/usr/local/bin/composer`;
- MariaDB;
- Redis with authentication;
- active system Cron;
- project domain `hell.hellpservice.ir`;
- project root `/www/acdomains/hell.hellpservice.ir`;
- OpenLiteSpeed document root `/www/acdomains/hell.hellpservice.ir/current/public` after release-symlink deployment;
- system and database operational time in `UTC`;
- business display timezone `Asia/Tehran`;
- application files owned by `www:www` where appropriate.

The installer must re-check all environment facts instead of assuming they remain unchanged.

## 2.2 Framework and dependency baseline

- Use Laravel `13.x`, locked through `composer.lock`.
- Use PHP 8.4.
- Use Composer and PSR-4 autoloading.
- Use MariaDB with `utf8mb4`.
- Use Redis for queue, cache, rate limits, and distributed locks.
- Use supported, maintained Composer packages only.
- Record package licenses and reject incompatible or abandoned dependencies.
- Re-verify current official documentation before implementing every external integration.

## 2.3 Two PHP runtimes

The two PHP binaries are intentional:

- CLI PHP: Composer, Artisan, Scheduler, queues, backups, release tooling;
- LSPHP: HTTP requests, installer, updater, Telegram webhook, payment callbacks.

Preflight must inspect version, extensions, `php.ini`, timezone, `disable_functions`, limits, and OPcache separately for both runtimes.

## 2.4 Release directory layout

Use atomic symlink releases:

```text
/www/acdomains/hell.hellpservice.ir/
├── current -> releases/1.0.0
├── releases/
│   ├── 0.9.0-rc.1/
│   ├── 1.0.0/
│   └── ...
└── shared/
    ├── .env
    ├── storage/
    ├── backups/
    ├── update-packages/
    ├── restore-work/
    ├── provider-certificates/
    └── installer.lock
```

No deployment may expose the Laravel project root to the web. Only `public/` is web-accessible.

## 2.5 Single Cron entry

Use exactly one Cron entry for Laravel Scheduler:

```cron
* * * * * cd /www/acdomains/hell.hellpservice.ir/current && /www/server/php/84/bin/php artisan schedule:run >> /dev/null 2>&1
```

The Scheduler may dispatch many independent tasks. Queue workers are managed by Supervisor, systemd, or an aaPanel-compatible process manager and are not separate Cron entries.

---

# 3. Team Structure and Agent Management

The Lead Agent may adjust headcount, but the following responsibilities must exist and must be independently reviewed.

## 3.1 Product and Business Analyst

Responsibilities:

- convert this document into requirement IDs and acceptance scenarios;
- maintain domain glossary;
- define user journeys;
- ensure no requirement is omitted;
- identify ambiguous business rules before implementation reaches a financial boundary;
- verify Persian user-facing behavior and terminology.

## 3.2 Solution Architect

Responsibilities:

- Modular Monolith architecture;
- module boundaries and dependency rules;
- API and adapter contracts;
- state machines;
- error taxonomy;
- extensibility for future web UI;
- architecture decision records.

## 3.3 Database and Financial Integrity Engineer

Responsibilities:

- normalized schema;
- foreign keys and indexes;
- Money value objects;
- immutable ledger;
- holds, captures, reversals, refunds;
- reconciliation;
- concurrency and isolation tests;
- exact-amount reservation.

## 3.4 Telegram UX Engineer

Responsibilities:

- webhook processing;
- conversations and state;
- callback tokenization;
- keyboards, colored buttons, premium emoji;
- message editing and delivery;
- Persian RTL-aware copy;
- Telegram rate-limit handling.

## 3.5 Payment Integration Engineer

Responsibilities:

- wallet payment;
- card-to-card manual and automatic verification;
- gift-card manual and automatic verification;
- direct USDT BEP20;
- Zarinpal;
- NOWPayments;
- rate sources;
- callback/webhook verification;
- provider fakes and contract tests;
- reconciliation and payment incident handling.

## 3.6 Panel and Provisioning Engineer

Responsibilities:

- Marzban and PasarGuard adapters;
- capability discovery;
- create/update/suspend/delete/sync operations;
- remote idempotency and reconciliation;
- health checks;
- server capacity and fallback selection.

## 3.7 Identity, Access, and Support Engineer

Responsibilities:

- customer identity;
- phone verification;
- SMS providers;
- administrator RBAC/ABAC;
- customer tiers and tags;
- support tickets;
- privacy boundaries.

## 3.8 DevOps, SRE, and Release Engineer

Responsibilities:

- aaPanel/OpenLiteSpeed deployment;
- installer;
- updater;
- queue workers;
- scheduler;
- backups and restore;
- health checks;
- observability;
- rollback and release package.

## 3.9 QA and Test Automation Engineer

Responsibilities:

- test architecture;
- requirement coverage;
- unit, integration, contract, E2E, concurrency, performance, chaos, restore, and security tests;
- evidence reports;
- release gate enforcement.

## 3.10 Independent Security Reviewer

Responsibilities:

- threat model review;
- authorization review;
- payment and webhook review;
- SSRF, file upload, secret storage, and logging review;
- dependency and supply-chain review;
- release-blocking security sign-off.

## 3.11 Team workflow rules

- One bounded GitHub Issue owns each independently reviewable task; one PR should normally implement that task.
- PRs link the owning Issue/requirements and the validation that proves the change. Do not require duplicate traceability/evidence documents.
- No author alone approves High/Critical financial, authorization, security, provider, schema, deployment/release, secret, or irreversible work unless that exact action was explicitly pre-authorized by the Owner.
- Use independent/specialist review when risk requires it. Do not add reviewers or checklists that provide no decision value.
- Prefer small, cohesive, independently reviewable changes; do not split tightly coupled work or create tasks merely to increase task count.
- A phase ends when its GitHub exit criteria and applicable quality/security/financial/operations gates pass. Create a durable phase/release report only when a release, audit, or operations consumer actually needs it.
---

# 4. Architecture

## 4.1 Architectural style

Use a Laravel Modular Monolith. Keep business logic independent from Telegram, HTTP callbacks, database details, and external providers.

Suggested modules:

- `Core`
- `Identity`
- `AccessControl`
- `Customers`
- `Agents`
- `Catalog`
- `Panels`
- `Orders`
- `Payments`
- `Wallet`
- `Provisioning`
- `Services`
- `Promotions`
- `Referrals`
- `Support`
- `Broadcast`
- `Content`
- `Notifications`
- `Reporting`
- `Operations`
- `Installer`
- `Updater`
- `Telegram`

## 4.2 Layer rules

Each module may contain:

- Domain entities, value objects, policies, and state machines;
- Application commands, queries, handlers, and orchestration services;
- Infrastructure repositories, database models, HTTP clients, and adapters;
- Presentation handlers for Telegram or limited web routes.

Rules:

1. Telegram handlers call Application Services; they never implement financial or provisioning rules.
2. External APIs are accessed only through contracts and adapters.
3. Eloquent models must not become unbounded business-logic containers.
4. Cross-module writes occur through explicit Application Services and transactions.
5. Domain events are internal coordination tools, not a reason to create distributed complexity.
6. Use Transactional Outbox for reliable external side effects.
7. Use typed enums and state transition services; do not store arbitrary workflow strings.
8. Future web UI must reuse the same Application Services.

## 4.3 Repository structure

A suitable structure is:

```text
app/
├── Modules/
│   ├── Identity/
│   ├── AccessControl/
│   ├── Catalog/
│   ├── Panels/
│   ├── Orders/
│   ├── Payments/
│   ├── Wallet/
│   ├── Provisioning/
│   ├── Services/
│   ├── Support/
│   ├── Broadcast/
│   └── Operations/
├── Telegram/
├── Shared/
└── Providers/
```

Do not force every module into excessive DDD ceremony. Use clear boundaries and simple code.

## 4.4 Coding standards

- `declare(strict_types=1);`
- PSR-12;
- typed properties, arguments, and return values;
- no monetary `float`;
- no dynamic SQL concatenation;
- prepared queries/Eloquent/Query Builder only;
- no hidden global state;
- dependency injection for clocks, random generators, HTTP clients, and external providers;
- translation-ready user-facing text;
- validation at input and domain boundaries;
- output escaping appropriate to Telegram HTML/Markdown and web HTML;
- centralized exception taxonomy;
- secrets never committed;
- no disabled TLS verification;
- no broad `catch (Throwable) {}` that suppresses an error;
- no direct `env()` use outside configuration files;
- smallest safe change per commit.

## 4.5 Mandatory tooling

Configure and run:

- Laravel Pint;
- PHPStan or Larastan at a strict practical level;
- PHPUnit or Pest with parallel support where safe;
- Composer audit;
- dependency license report;
- migration tests on MariaDB;
- secret scanning;
- static grep/architecture tests for forbidden patterns;
- CI pipeline that blocks merges on failed mandatory checks.

---

# 5. Core Product Rules

## 5.1 Language, time, and money

- Default language: Persian.
- Architecture: multilingual from the first release.
- Internal timestamps: UTC.
- User and business display timezone: `Asia/Tehran`.
- The presentation layer supports Jalali/Persian calendar formatting while all stored timestamps remain UTC.
- All Iranian fiat amounts are stored as integer IRR.
- The bot displays Iranian fiat in Toman using explicit, tested conversion.
- Never infer whether an amount is IRR or Toman from context; currency and unit must be explicit.
- Crypto decimals use fixed-precision decimal values, never binary float.

## 5.2 Account types

Separate these concepts:

- account type: customer or agent;
- customer tier;
- account status: active, limited, suspended, blocked;
- manually assigned tags;
- identity verification status;
- administrator status and permissions.

A Telegram user may be a customer/agent and an administrator simultaneously. Administrative authorization remains separate from commercial account classification.

## 5.3 Customer tiers

Seed four configurable tiers:

1. `new`: no successful purchase;
2. `normal`: at least one successful purchase;
3. `loyal`: default at least 3 successful purchases and 30 days membership;
4. `vip`: default at least 10 successful purchases and 90 days membership.

Requirements:

- thresholds are editable;
- total-spend criteria exist but are disabled by default;
- failed, canceled, refunded, or charge-reversed orders do not count;
- promotion is recalculated after successful purchase and daily;
- automatic downgrade is disabled by default but configurable;
- an administrator may override and lock a tier;
- all tier changes are audited.

## 5.4 Agent/reseller model

Version 1 uses one visible agent type but a future-ready pricing profile.

An agent may have:

- approval status;
- active, limited, or suspended status;
- general discount;
- category, server, offering, and action-specific prices;
- bulk-purchase access;
- dedicated payment method access;
- different limits;
- prepaid wallet;
- ability to buy services for end customers;
- reports limited by permission.

### 5.4.1 Agent/reseller application and lifecycle

Provide a complete Telegram workflow:

1. An eligible customer opens **Request Cooperation** and sees the current terms and required information.
2. The customer submits the request once; duplicate active requests are rejected idempotently.
3. The request enters `submitted`, then `under_review` when claimed by an authorized administrator.
4. The reviewer can inspect the customer's account age, successful purchases, refunds, identity status, risk flags, tickets, and configured commercial criteria.
5. Approval assigns an agent profile and pricing profile atomically and immediately exposes the agent menu.
6. Rejection requires a reason and sends an editable localized response. Reapplication is allowed only when the configured cooldown or manual release permits it.
7. Suspension or restoration preserves history and does not delete pricing, orders, or financial records.
8. Every transition records actor, reason, timestamp, correlation ID, previous state, and new state.

Agent menu capabilities:

- agent profile and status;
- customer join date and agent approval date;
- number of services purchased as an agent;
- single-service purchase;
- bulk purchase;
- wallet top-up;
- purchased-service list and search;
- agent sales/purchase report;
- support entry point.

Bulk purchase creates one parent order and one independently idempotent child item per requested service. A failure of one child must not recreate successful children or cause a second debit.

## 5.5 Administrator authorization

Use granular permissions with reusable roles.

Seed role templates:

- Owner/Super Admin;
- Support;
- Finance;
- Technical Operations;
- Sales and Content.

Rules:

- one Telegram administrator can hold multiple roles;
- each permission has per-admin tri-state override: inherit, allow, deny;
- explicit deny overrides role grants;
- Owner has full access and can transfer ownership only through a hardened flow;
- menu visibility is not authorization; every action re-checks permission;
- sensitive actions require confirmation and may require re-authentication or second approval;
- all permission changes are audited.

Include granular permissions for at least:

- view basic customer data;
- view verified phone and identity data;
- edit customer state and tags;
- view wallet balance;
- view ledger entries;
- add or subtract funds;
- approve/reject manual receipts;
- manage automatic verification providers;
- approve refunds;
- view orders and payments;
- manage customer services;
- retry provisioning;
- manage panels and servers;
- manage products and pricing;
- manage gateways;
- view financial reports;
- manage tickets;
- send broadcasts;
- manage content and menus;
- manage administrators;
- manage global settings;
- run backups and restores;
- install updates and roll back.

## 5.6 Phone and identity verification

Support two independent phone verification methods:

- Telegram contact sharing;
- SMS OTP.

A policy may require:

- no phone verification;
- Telegram contact only;
- OTP only;
- either method;
- both methods.

Rules:

- for Telegram contact verification, `contact.user_id` must equal the sender's Telegram user ID;
- normalize and validate Iranian mobile numbers;
- one verified mobile number belongs to only one Telegram account;
- an authorized administrator may release a number, with audit log;
- a verification normally remains valid indefinitely;
- changing the number invalidates previous verification;
- an administrator may require re-verification globally, by customer tier, gateway, or individual user;
- store verification method, time, and policy version;
- a gateway that specifically requires OTP cannot accept contact-only verification unless its policy says either is sufficient.

SMS providers for version 1:

- primary: Melli Payamak;
- fallback: Kavenegar.

Default OTP policy:

- 6 digits;
- 2-minute validity;
- 60-second resend cooldown;
- maximum 5 attempts per code;
- configurable daily limit by phone, Telegram account, IP, and provider;
- store only a secure hash of the OTP;
- never log OTP values;
- fallback provider is used only after a definitive primary-provider failure, not after an uncertain timeout that may have sent the message.

Additional identity fields:

- bank card;
- national ID;
- full name;
- optional bank-card ownership verification;
- optional national-ID and mobile ownership matching.

Each identity item has states: unverified, pending, verified, rejected. Sensitive values are encrypted; masked or keyed hashes are used for lookup and uniqueness.

---

# 6. Gateway Access Rule Engine

Every payment method must have an independent policy engine. It determines visibility and eligibility using:

- global active/maintenance state;
- customer or agent account type;
- customer tier;
- tags;
- account age;
- successful-purchase count;
- historical spend;
- new/returning status;
- current order amount;
- order action type;
- category, product, plan offering, and server;
- phone/contact/OTP status;
- bank-card or national-ID status;
- date range;
- day of week and time window;
- per-user allow/deny override;
- transaction count and amount limits;
- provider health and circuit-breaker state.

Evaluation precedence:

1. gateway disabled or maintenance -> deny;
2. blocked/suspended account -> deny;
3. explicit per-user deny -> deny;
4. identity requirements -> evaluate;
5. explicit per-user allow may bypass normal commercial rules but not hard security rules;
6. account type, tier, tags, history, amount, product, and time rules;
7. default policy.

New gateways are disabled by default until configured, tested, and explicitly activated.

---

# 7. Catalog, Products, Servers, and Offerings

## 7.1 Independent concepts

Model separately:

- Category: display organization;
- Product/Plan: commercial definition;
- Panel Connection: credentials and API endpoint;
- Service Target: inbound, group, template, host, or equivalent target inside a panel;
- Sales Server: user-visible location/server;
- Plan Offering: a product offered on a specific sales server with server-specific behavior.

## 7.2 Plan Offering configuration

Each offering independently defines:

- price in IRR;
- customer and agent prices;
- duration;
- data allowance;
- connection/device/user limits where supported;
- target template/group/inbound;
- availability and sort order;
- capacity;
- customer tier and tag eligibility;
- server selection mode;
- fallback rules;
- trial eligibility;
- custom-plan availability;
- renewal packages;
- add-volume packages;
- add-days packages;
- combined add-on packages;
- reset-traffic operation;
- plan-change operation;
- auto-renew behavior;
- discount eligibility;
- min/max purchase quantity;
- account naming policy;
- delivery text and instructions.

## 7.3 Server selection modes

Support per offering:

- customer selects server;
- system selects server;
- customer may choose automatic or a visible server.

Server fallback:

- disabled by default;
- enabled only by administrator configuration;
- administrator defines allowed fallback targets and priority/weight;
- verify capacity, health, protocol, duration, data, and required capabilities;
- if the customer selected a specific server, fallback is allowed only if that policy was enabled and disclosed before payment;
- record requested server, attempted servers, reason, and final server;
- never charge extra after payment because of fallback;
- if no compatible target exists, move the order to manual review.

## 7.4 Service operations

Model separately:

- renewal;
- add data;
- add days;
- combined add data and days;
- reset usage;
- suspend;
- activate;
- delete;
- revoke/change subscription link;
- change plan;
- synchronize;
- import existing service.

Only show an operation if the selected server and adapter support it and it is enabled for that offering.

## 7.5 Custom plans

Per server/offering configuration:

- enabled/disabled;
- minimum and maximum data;
- data step;
- minimum and maximum days;
- day step;
- base price;
- price per GB;
- price per day;
- minimum order amount;
- customer and agent pricing;
- eligible tiers/tags;
- custom username allowed;
- discount eligibility.

Default price formula:

```text
base price + (data GB * price per GB) + (days * price per day)
```

Price components must be snapshotted into the order.

## 7.6 Service username policy

Support:

- generated from Telegram user ID;
- generated unique suffix for multiple services;
- customer-selected username when enabled;
- English letters, digits, and adapter-approved separators only;
- configurable min/max length;
- reserved words;
- uniqueness check before payment and immediately before provisioning;
- stable normalized form;
- no untrusted username placed directly in a URL or shell command.

## 7.7 Trial service

Trial is a separate zero-cost order type, not a fake successful payment.

Per server configuration:

- enabled;
- data and duration;
- daily capacity;
- eligible customer tiers/tags;
- phone/contact/OTP requirement independently optional;
- mandatory channel-membership requirement independently optional;
- fallback option;
- one-per-user policy;
- one-per-phone policy when phone is available;
- administrator reset/regrant permission;
- anti-abuse rules;
- delivery text.

A trial may be configured with no phone or identity requirement.

## 7.8 Auto-renew

- enabled per service;
- wallet only in version 1;
- customer selects renewal package;
- one atomic hold and capture;
- no partial debit;
- idempotent scheduler behavior;
- notify success, insufficient balance, and failure;
- price-change behavior configurable per offering:
  - stop and request confirmation;
  - continue at new price;
  - continue only within a configured absolute or percentage increase limit.

## 7.9 Service modes, protocol profiles, and delivery policy

An offering may represent a shared service, a dedicated/individual service, or another administrator-defined service mode. The visible labels are editable, while the internal mode is typed and validated.

Per offering and panel target, configure:

- allowed protocol profiles and whether the customer may select one;
- host/group/inbound/template mapping;
- typed host, SNI, path, port, flow, and transport options supported by the adapter;
- whether one subscription link, individual configuration links, or both are delivered;
- which individual configuration positions are displayed;
- a configurable threshold above which only the subscription link is shown;
- QR availability for the subscription link and individual configurations;
- backup/recovery delivery text;
- whether link rotation, protocol change, or location change is customer-visible or administrator-only.

Never expose raw panel JSON, credentials, arbitrary URLs, or unrestricted template code to customers or normal administrators.

## 7.10 Client application and connection-guide catalogue

Provide an administrator-managed catalogue that customers can open from the main menu. Each resource contains:

- localized title and description;
- operating system/platform;
- official download or guide URL;
- optional media/tutorial message;
- language;
- customer/agent/tier/tag visibility rules;
- sort order;
- active state;
- normal emoji and optional premium emoji;
- last validation time and responsible administrator.

Validate URL scheme and length. Display resources without fetching arbitrary remote content. Link changes are audited. Disabled resources remain in history but disappear from the customer menu.

---

# 8. Panel Adapter Architecture

## 8.1 Version 1 adapters

- `MarzbanAdapter`
- `PasarGuardAdapter`
- `FakePanelAdapter` for deterministic tests

## 8.2 Required contract

The contract must expose capabilities and supported operations, including:

- test connection;
- return panel type and version;
- health status;
- discover capabilities;
- create user/service;
- find by remote ID and deterministic username;
- fetch status and usage;
- update expiry;
- add or set data allowance;
- reset usage;
- suspend and activate;
- delete;
- revoke or regenerate subscription link;
- get subscription links and QR source;
- synchronize user;
- list compatible targets/templates/groups;
- identify definitive versus retryable errors.

## 8.3 Integration rules

- Use HTTPS with certificate verification.
- Never set TLS verification to false.
- For a self-signed panel, require a custom CA certificate or certificate pin and show a security warning.
- Encrypt credentials.
- Redact secrets and subscription URLs in logs.
- Implement timeouts, bounded retries, exponential backoff with jitter, and circuit breaker.
- Record panel version and test date.
- Contract-test against the actual installed version before production activation.
- Do not assume Marzban and PasarGuard have identical semantics.
- Translate adapter-specific behavior into explicit capabilities.

## 8.4 Remote idempotency

Before every retry after an uncertain result:

1. search the remote panel using deterministic identifiers;
2. compare expected service attributes;
3. adopt the existing remote service if it matches;
4. raise a conflict for manual review if it exists but differs;
5. create a new service only when absence is confirmed.

A timeout after a remote create must never immediately trigger another create.

---

# 9. Order, Payment, and Provisioning Separation

## 9.1 Required entities

Separate at minimum:

- Cart/Quote;
- Order;
- Order Item;
- Price Snapshot;
- Payment Intent;
- Payment Attempt;
- Provider Transaction;
- Manual Submission;
- Refund;
- Provisioning Operation;
- Service Subscription;
- Delivery Attempt.

## 9.2 Order states

Use a guarded state machine such as:

- `draft`
- `quoted`
- `awaiting_payment`
- `payment_pending_review`
- `paid`
- `provisioning_queued`
- `provisioning`
- `completed`
- `needs_review`
- `canceled`
- `refund_pending`
- `refunded`
- `partially_refunded`

Define allowed transitions and actors. No arbitrary status update is permitted.

## 9.3 Payment Intent states

- `created`
- `awaiting_user_action`
- `submitted`
- `verifying`
- `pending_manual_review`
- `authorized`
- `captured`
- `failed`
- `expired`
- `canceled`
- `refund_pending`
- `refunded`
- `partially_refunded`

The exact model may vary by provider, but authoritative success must be distinguishable from user return, submission, or provider pending state.

## 9.4 Provisioning states

- `queued`
- `running`
- `uncertain_remote_result`
- `retry_scheduled`
- `succeeded`
- `failed_final`
- `needs_review`
- `compensating`
- `compensated`

## 9.5 Non-negotiable invariants

1. A paid service cannot be provisioned before authoritative payment capture, except explicit trial/gift/admin-grant order types.
2. One payment transaction cannot pay two orders unless a documented batch model explicitly links them.
3. One paid order item produces at most one active remote service identity.
4. Duplicate Telegram updates, callbacks, webhooks, queue jobs, and admin clicks are idempotent.
5. A payment success cannot be lost because Telegram delivery failed.
6. A Telegram message cannot be treated as a financial source of truth.
7. All financial state transitions occur inside database transactions and are backed by unique constraints.
8. Cache locks are supplemental; database uniqueness is the final duplicate-effect barrier.
9. A remote service discovered after an uncertain timeout is adopted, not recreated.
10. Failed provisioning after successful payment keeps the payment successful and moves the order to retry/manual review; it does not collect payment again.

## 9.6 Idempotency keys

Create stable idempotency keys for:

- Telegram update;
- callback token/action;
- payment intent creation;
- provider callback/webhook event;
- provider transaction;
- manual receipt decision;
- bank transaction match;
- gift-card validation/redeem action;
- wallet hold/capture/release;
- order item provisioning;
- service renewal/add-on;
- refund;
- referral reward;
- broadcast recipient action;
- batch gift;
- backup;
- update execution.

---

# 10. Payment Architecture

## 10.1 Version 1 payment methods

Implement:

- internal wallet;
- card-to-card bank transfer;
- gift card;
- direct USDT on BEP20;
- Zarinpal;
- NOWPayments.


## 10.2 Common Payment Provider contract

Every provider must expose an appropriate subset of:

- configure and validate configuration;
- test connection;
- report capabilities;
- create payment intent/invoice;
- present user instructions;
- accept user submission;
- verify callback or webhook authenticity;
- fetch authoritative provider status;
- normalize provider transaction;
- cancel or expire an intent;
- full or partial refund where supported;
- reconcile historical transactions;
- health status and circuit breaker;
- fake/sandbox implementation.

No provider may directly provision a service. Providers only change payment state through the Payment Application Service.

## 10.3 Common gateway settings

Each gateway supports:

- active/disabled/maintenance;
- display priority;
- test/live mode;
- customer access rules;
- minimum and maximum amount;
- supported actions: purchase, renewal, add data, add days, wallet top-up, agent bulk purchase;
- category/product/offering/server constraints;
- identity policy;
- exact-amount adjustment toggle;
- payment-intent expiry;
- daily count and amount limits;
- user instruction text;
- refund policy;
- provider timeout/retry configuration;
- health and circuit-breaker status;
- manual fallback policy;
- notification policy.

---

# 11. Card-to-Card Payment: Manual and Automatic Verification

Card-to-card must support both manual and automatic verification from the first release. The automatic implementation must be a safe extensible foundation that can connect to future banking, transaction aggregation, statement, or verification APIs without rewriting order logic.

## 11.1 Verification modes

Configure independently per card-to-card gateway or destination account:

- `manual_only`;
- `automatic_only`;
- `automatic_then_manual`;
- `automatic_and_manual_parallel` for controlled operational use;
- `automatic_with_manual_approval_above_limit`;
- `manual_fallback_on_provider_failure`.

Default recommended mode: `automatic_then_manual`.

## 11.2 Destination bank accounts

Allow multiple destinations with:

- bank name;
- display label;
- card PAN stored encrypted and displayed masked;
- SHABA/IBAN stored encrypted and displayed masked;
- account number if needed, encrypted;
- holder name;
- active/maintenance state;
- priority and weight;
- minimum/maximum amount;
- daily transaction count and value limits;
- exact-amount range policy;
- assigned automatic-verification provider;
- allowed customer rules;
- rotation/fallback policy;
- optional sender-card requirement;
- operational notes visible only to authorized administrators.

## 11.3 User submission

Depending on configuration, collect:

- receipt image;
- paid amount;
- payment time;
- source card last digits or full card under encryption;
- sender name;
- bank trace/reference number;
- optional description;
- user confirmation that the transfer was completed.

Receipt images are private. Store Telegram `file_id`, `file_unique_id`, safe metadata, and optional content hash after controlled download. A hash alone must not be the only reason for rejection because visually identical receipts can be encoded differently.

## 11.4 Exact non-round amount

Per payment method/destination, exact-amount adjustment can be enabled or disabled.

Default range:

- 100 to 999 Toman;
- stored as 1,000 to 9,990 IRR.

Store separately:

- `base_amount_irr`;
- `adjustment_amount_irr`;
- `payable_amount_irr`.

Rules:

- reserve a unique payable amount for the configured destination and active matching window;
- two active intents that could collide in the same matching scope must not receive the same exact amount;
- show exactly the stored database value;
- release reservation after expiry;
- keep late submissions for review;
- the adjustment is part of the final paid amount and is non-refundable;
- uniqueness is enforced with database constraints and transaction locking;
- use cryptographically secure randomness through an injectable generator.

## 11.5 Automatic bank transaction provider architecture

Create `BankTransactionVerificationProvider` with capabilities such as:

- `testConnection()`;
- `capabilities()`;
- `pullTransactions(cursor, from, to)`;
- `fetchTransaction(providerTransactionId)`;
- `verifyWebhook(request)`;
- `normalizeTransaction(payload)`;
- `health()`;
- `reconcile(period)`.

Version 1 must include:

1. `FakeBankTransactionProvider` for deterministic tests;
2. `GenericRestBankTransactionProvider` as a real configurable foundation;
3. a documented extension template for provider-specific adapters.

## 11.6 Generic REST bank provider

The administrator can configure, through protected settings:

- provider name;
- base HTTPS URL;
- pull endpoint and HTTP method;
- optional transaction-detail endpoint;
- optional webhook endpoint path generated by this application;
- authentication type: Bearer token, API key header, Basic auth, HMAC request signing, or mTLS if implemented safely;
- encrypted credentials;
- custom static headers from an allowlisted schema;
- request timeout;
- polling interval;
- pagination/cursor strategy;
- rate-limit behavior;
- webhook signature algorithm and secret;
- optional source IP allowlist;
- safe JSON-path field mappings;
- timezone of provider timestamps;
- status mapping;
- amount unit mapping: IRR/Toman with explicit conversion;
- destination account/card mapping;
- transaction ID, reference number, sender-card, sender-name, occurred-at, settled-at fields;
- test/live mode;
- certificate pin/custom CA if required.

Security requirements:

- HTTPS only;
- block localhost, private, link-local, metadata, multicast, and reserved IP ranges;
- resolve and re-check DNS at connection time to prevent DNS rebinding;
- optionally enforce an administrator domain allowlist;
- no arbitrary scripts, templates, PHP, SQL, or shell commands in mappings;
- only safe predefined transformations: trim, digits-only, date parse, amount conversion, status map, hash/mask;
- never log secrets or full sensitive payloads;
- validate response content type and maximum body size;
- store raw payload encrypted only when operationally necessary and subject to retention;
- store a canonical payload hash for evidence and deduplication.

## 11.7 Normalized bank transaction model

Normalize external transactions into records containing at least:

- provider configuration ID;
- provider transaction ID;
- provider event ID if webhook-based;
- amount in IRR;
- currency;
- occurred time;
- settlement time;
- normalized status;
- destination account/card/SHABA keyed hash;
- masked destination;
- sender card keyed hash and masked value if available;
- sender name if available and permitted;
- trace/reference number;
- description, redacted;
- payload hash;
- ingestion method: pull, webhook, manual import;
- first seen and last seen time;
- reconciliation status.

Use unique constraints on provider + provider transaction ID and on webhook event IDs.

## 11.8 Automatic matching engine

Matching must use deterministic rules and produce explainable evidence.

Mandatory matching factors:

- payment intent is active or accepted as late according to policy;
- authoritative transaction status is settled/successful;
- amount matches exact payable amount;
- destination account/card matches;
- transaction time is within configured window;
- transaction has not been consumed by another payment;
- order, user, and gateway remain eligible.

Optional factors:

- sender card hash;
- sender name;
- reference number entered by user;
- verified identity/card ownership;
- provider-specific metadata.

Rules:

- exact unambiguous match may auto-capture;
- zero matches remain pending or move to manual review according to policy;
- multiple candidate intents or multiple candidate transactions are ambiguous and must not auto-approve;
- amount mismatch, wrong destination, duplicate transaction, reversed transaction, or suspicious time shift goes to manual review or rejection according to explicit policy;
- matching produces a score and a human-readable reason list, but hard constraints cannot be bypassed by score;
- a provider transaction can be consumed only once;
- capture and transaction consumption occur atomically;
- automatic approval respects configurable maximum amount and customer-risk limits;
- high-value or high-risk transactions may require a second human approval.

## 11.9 Webhook and polling behavior

- Accept webhooks only on a secret, provider-specific endpoint.
- Verify signature before parsing business fields.
- Store webhook event idempotently before processing.
- Return quickly and process asynchronously.
- Polling uses cursor/checkpoint records and overlap windows to avoid missed transactions.
- Re-poll recent windows to detect delayed settlement or reversals.
- Provider outage opens a circuit breaker and triggers manual fallback if enabled.
- A timeout with unknown result is not definitive failure.

## 11.10 Manual review

Authorized administrators can:

- view safe receipt and normalized transaction candidates;
- approve or reject with mandatory reason;
- link a bank transaction to a payment intent;
- detach an incorrect candidate before capture if no financial effect occurred;
- escalate high-value cases;
- view provider evidence and matching reasons;
- never delete financial evidence.

Concurrent decisions by two administrators must create only one financial result.

## 11.11 Reconciliation

Scheduled reconciliation must detect:

- settled bank transaction not linked to a payment;
- captured payment with missing provider transaction;
- one bank transaction linked more than once;
- late transaction;
- reversal after capture;
- amount/destination mismatch;
- provider cursor gaps;
- provider health degradation.

Reversal after service provisioning is a critical financial incident. Freeze risky automated actions, alert the Owner, and create an incident case; do not silently delete the service or ledger entry.

---

# 12. Gift Card Payment: Manual and Automatic Verification

Gift cards must support both manual and automatic verification. Different brands/types may use different providers and policies.

## 12.1 Submission modes

Per gift-card type configure:

- image only;
- code only;
- either image or code;
- both image and code required.

Store:

- gift-card type/brand;
- claimed face value and currency;
- encrypted code;
- keyed code hash for uniqueness;
- image private-file reference;
- Telegram file identifiers;
- optional serial number;
- submission time;
- verification provider;
- review/verification result;
- reviewer or automatic run;
- redemption evidence.

Never log or display the full code after initial submission. Administrators see only masked values unless a narrowly scoped permission and explicit reveal action are used; reveal actions are audited.

## 12.2 Verification modes

Configure per gift-card type:

- `manual_only`;
- `automatic_only`;
- `automatic_then_manual`;
- `automatic_with_manual_approval_above_limit`;
- `manual_fallback_on_provider_failure`.

Default recommended mode: `automatic_then_manual`.

## 12.3 Gift-card provider contract

Create `GiftCardVerificationProvider` with capabilities such as:

- `testConnection()`;
- `capabilities()`;
- `validateCode()`;
- `validateImage()` where provider supports image input;
- `reserveOrLock()` where provider supports it;
- `redeemOrCapture()`;
- `releaseOrCancel()`;
- `queryStatus()`;
- `verifyWebhook()`;
- `normalizeResult()`;
- `health()`;
- `reconcile()`.

Version 1 must include:

1. `FakeGiftCardVerificationProvider`;
2. `GenericRestGiftCardVerificationProvider`;
3. a provider-specific adapter template and documentation.

## 12.4 Generic REST gift-card provider

Configurable fields include:

- provider name;
- supported gift-card types;
- HTTPS base URL;
- validate endpoint;
- optional reserve endpoint;
- optional redeem/capture endpoint;
- optional release endpoint;
- optional status endpoint;
- optional webhook endpoint;
- HTTP methods;
- encrypted authentication credentials;
- Bearer/API-key/Basic/HMAC/mTLS authentication;
- request and response safe field mappings;
- provider status mapping;
- amount/currency mapping;
- timeout, retry, and rate limits;
- webhook signature settings;
- test/live mode;
- domain allowlist and custom CA/pin.

Apply the same SSRF, TLS, payload-size, mapping, secret, and logging controls as the generic bank provider.

## 12.5 Automatic validation and capture policy

A distinction is mandatory:

- validation: the provider says the code appears valid or has balance;
- reservation: the code/value is locked for this transaction;
- capture/redemption: the value is irreversibly consumed or transferred.

Rules:

1. Automatic financial approval is allowed only when the provider result is authoritative and the code can be atomically reserved/captured, or when a documented business policy accepts the risk for a provider with equivalent guarantees.
2. If the provider can only check validity/balance but cannot lock or redeem, a positive result normally goes to manual review rather than immediate payment capture.
3. Code hash uniqueness prevents re-submission in this application, but cannot alone prove the code was not used elsewhere.
4. Validation and redemption calls must be idempotent.
5. A code cannot fund more than one payment.
6. Amount, currency, brand, region, expiry, and redemption state must match the configured policy.
7. Partial-value cards are handled only if the provider and business policy explicitly support partial capture; otherwise move to manual review.
8. A provider response such as pending, unknown, already redeemed, blocked, region mismatch, or amount mismatch must not auto-approve.
9. Images are automatically verified only when the selected provider explicitly supports secure image upload. OCR is not a financial authority by itself.
10. If an image-only submission requires a code and no provider can extract/validate it authoritatively, route to manual review.

## 12.6 Gift-card states

Use guarded states such as:

- `submitted`
- `validating`
- `valid_unreserved`
- `reserved`
- `redeeming`
- `captured`
- `pending_manual_review`
- `invalid`
- `already_used`
- `expired`
- `provider_unavailable`
- `rejected`
- `released`

## 12.7 Manual review

Authorized administrators can:

- inspect the private image;
- inspect a masked code and optionally reveal under permission;
- view provider results;
- approve/reject with reason;
- enter external redemption evidence;
- retry provider validation;
- escalate high-value cases;
- never delete the submission or provider evidence.

## 12.8 Gift-card reconciliation

Detect:

- validated but never captured;
- captured by provider but local payment not captured;
- local payment captured but provider has no redemption;
- duplicate code hash;
- provider reports later reversal/cancellation;
- amount/currency mismatch;
- pending validation beyond SLA.

---

# 13. Other Payment Methods

## 13.1 Wallet

Wallet payment is internal and authoritative only through the immutable ledger and hold/capture flow defined later.

## 13.2 Direct USDT on BEP20

Requirements:

- clearly display network as `BEP20`;
- configurable destination wallet;
- exact USDT amount;
- TXID submission;
- optional receipt image;
- unique TXID;
- verify network, destination, amount, confirmations, and transaction status;
- manual review is available;
- define `BlockchainTransactionVerificationProvider` so an explorer/node API can automate verification;
- late, underpaid, overpaid, partial, wrong-network, or ambiguous payments go to manual review;
- never treat a screenshot as authoritative proof.

### USDT rate providers

Version 1 supports:

- managed Manual IRR-per-USDT rate;
- Nobitex public USDT/RLS market data;
- Wallex public `USDTTMN` spot-market data;
- future adapters.

Runtime selection is deterministic: `Nobitex -> Wallex -> Manual`. The Manual rate is a protected DB-backed, versioned, audited product setting. Deployment configuration is bootstrap fallback only before a managed value exists; it is not a second runtime authority.

Configuration:

- primary and fallback order;
- buy/sell/last side selection;
- margin percentage;
- quote validity, default 15 minutes;
- maximum cache age;
- minimum/maximum sanity bounds;
- maximum divergence from secondary source;
- circuit breaker;
- manual emergency fallback only when explicitly enabled.

Snapshot into each quote:

- source;
- raw IRR rate;
- margin;
- final rate;
- order IRR amount;
- exact USDT amount;
- fetched and expiry times;
- provider response hash.

Use fixed-precision decimal and configurable round-up, default up to 6 decimal places.

## 13.3 Zarinpal

- use current official REST API, not obsolete SOAP integration;
- create request with amount, callback, description, and allowed metadata;
- browser return is not payment proof;
- authoritative server-to-server verification is required;
- compare authority, amount, currency/unit, and order;
- store reference ID and masked card metadata when provided;
- duplicate callbacks are idempotent;
- callback web page reveals minimum information and directs the user back to the bot;
- implement sandbox/test strategy if officially available; otherwise use a fake plus controlled low-value live acceptance test.

## 13.4 NOWPayments

- separate API key and IPN secret;
- verify IPN signature according to current official canonicalization rules;
- invalid signature changes no financial state;
- use the same selected IRR-per-USDT authority as direct USDT pricing (`Nobitex -> Wallex -> Manual`) as the explicit Version 1 business pricing proxy required for `price_currency=usd`; this is a pricing policy, not a claim that USD and USDT are economically identical;
- snapshot the selected rate source, exact rate, provider evidence identity, pricing-policy identity, derived USD `price_amount`, pay currency, and rounding policy immutably for each payment;
- do not introduce a separate USD/IRR provider or Manual USD rate in Version 1;
- compare payment ID, order ID, amount, pay currency, price currency, and status;
- re-query authoritative status for sensitive transitions;
- duplicate and out-of-order IPNs are idempotent;
- test with official sandbox where available;
- user redirect is not payment proof.

---

# 14. Wallet and Financial Ledger

## 14.1 Accounting model

Implement an immutable balanced ledger, using double-entry or an equivalent provably balanced model. A mutable `balance` column is not the source of truth; a cached balance is allowed only if continuously reconcilable.

Use Money value objects with integer IRR.

## 14.2 Balance buckets

At minimum:

- cash balance;
- gift/promotional credit.

Defaults:

- cash may be transferable;
- gift credit is non-transferable;
- both may be usable for purchases according to policy;
- consume expiring gift credit first;
- each credit grant may have expiry and transferability metadata.

## 14.3 Ledger transaction types

Include:

- external top-up;
- purchase;
- renewal;
- add data/days;
- wallet transfer;
- referral reward;
- administrator gift;
- promotional credit;
- refund;
- manual correction;
- reversal;
- hold;
- capture;
- release;
- fee.

## 14.4 Holds and concurrency

For wallet purchase/auto-renew:

1. create an atomic hold;
2. reduce available balance;
3. capture once on confirmed action;
4. release once on cancellation/failure;
5. never allow negative available balance;
6. prevent double spending through row locks, unique keys, and isolation-aware transactions.

## 14.5 Wallet transfer

Support transfer using Telegram ID, username resolution, or an application transfer ID.

Requirements:

- resolve to stable internal user ID;
- show limited recipient confirmation;
- confirm amount and recipient;
- atomic debit and credit;
- receipts to both users;
- configurable min/max amount and daily count/value limits;
- optional fee, default zero;
- tier/identity policy;
- no transfer to blocked account;
- gift credit non-transferable by default;
- idempotent transfer confirmation.

## 14.6 Administrator corrections

Authorized admin must select:

- amount;
- debit or credit;
- balance bucket;
- reason code;
- explanatory note;
- related ticket/order/payment when applicable.

Large corrections may require Owner or dual approval. Never edit/delete prior ledger entries; correct with a compensating entry.

## 14.7 Refunds

- wallet payment returns to original wallet buckets;
- Zarinpal/NOWPayments may refund through the original provider if supported and enabled;
- card-to-card, gift card, and USDT may be refunded manually or to wallet under documented policy;
- manual external refund requires evidence/reference;
- partial refunds supported;
- cumulative refund cannot exceed refundable captured amount;
- exact non-round card adjustment is non-refundable;
- every refund is idempotent;
- canceled/refunded order cancels pending referral rewards;
- refund destination override requires permission, reason, and audit.

## 14.8 Reconciliation

Scheduled reconciliation verifies:

- cached balance equals ledger-derived balance;
- debits equal credits globally and per transaction;
- expired holds are released;
- captured payment has expected ledger impact;
- completed paid order has valid payment and provisioning record;
- refund totals are valid;
- no duplicate provider transaction consumption;
- no negative balances;
- no orphan ledger entry.

Any unexplained mismatch is Critical: suspend affected automated operations and alert the Owner.

---

# 15. Pricing, Discounts, Gift Codes, Referrals, and Agent Pricing

## 15.1 Price calculation order

Use a deterministic order:

1. offering base price;
2. account/tier/agent price override;
3. eligible discount;
4. final order amount;
5. payment-method exact adjustment.

Snapshot every component into the order. Future configuration changes must not alter an existing quote after its defined validity period without explicit re-quote.

## 15.2 Discount codes

Support:

- fixed amount or percentage;
- maximum discount;
- minimum order amount;
- start/end time;
- global use limit;
- per-user use limit;
- first purchase only;
- customer/agent restriction;
- tier and tag restriction;
- category/product/offering/server restriction;
- action restriction: purchase, renewal, add data, add days;
- payment-method restriction;
- combination with agent price independently configurable;
- explicit permission to make an order fully free.

Version 1 allows one discount code per order. Reserve usage during payment and finalize only after successful payment. Expired/failed intents release the reservation.

## 15.3 Code types

Implement separately:

- discount code;
- wallet credit code;
- free service code.

Features:

- single or batch generation;
- owner-chosen or random code;
- expiration;
- single-use or multi-use;
- per-user limit;
- audience restriction;
- inactive without deleting history;
- usage reports;
- secure code storage using keyed hash where lookup is needed;
- full code shown only at creation/export and never later unless securely designed.

## 15.4 Referral program

- unique referral link per user;
- prevent self-referral;
- bind referrer on valid first entry;
- authorized owner may correct before first successful purchase;
- lock referrer after first successful purchase;
- reward may be fixed or percentage;
- reward referrer, new customer, or both;
- first purchase or all eligible purchases;
- min order, caps, product restrictions, expiry, and transferability;
- default pending period: 24 hours;
- refunded/canceled order cancels unreleased reward;
- anti-abuse checks for shared verified phone and other configured signals;
- never silently merge accounts based only on weak device/network evidence.

## 15.5 Agent pricing and bulk orders

Agent pricing profile supports:

- general discount;
- fixed or discounted price by category;
- by server;
- by offering;
- by action type;
- min bulk quantity;
- max quantity per order;
- discount-code combination policy;
- purchase multiple accounts in one order.

Each bulk order item has its own provisioning idempotency key and result. Retrying one failed item must not recreate successful items.

---

# 16. Customer Service Management

## 16.1 Service sources

Record source:

- paid bot purchase;
- trial;
- administrator grant;
- imported existing service;
- gift/service code.

Do not create fake payment transactions for non-paid service sources.

## 16.2 My Services view

Display:

- service name;
- server and plan;
- current state;
- total, used, and remaining data;
- expiration and days remaining;
- last synchronization time;
- subscription link;
- QR code;
- available actions based on actual capabilities.

If panel is unavailable, show cached data with timestamp and a non-alarming clear message.

## 16.3 Subscription links and QR

- generate QR on demand using a maintained Composer library;
- support primary and alternative links when adapter provides them;
- never log full subscription URLs or tokens;
- encrypt sensitive link material at rest;
- allow link revoke/regenerate only when server policy enables it;
- warn that the previous link will stop working;
- repeated action is idempotent;
- authorized admin can resend service details.

## 16.4 Import existing service

Flow:

1. user submits a subscription link or accepted identifier;
2. safely identify a registered server/panel;
3. do not make arbitrary network requests to the submitted URL;
4. fetch service through the known adapter and known endpoint;
5. show safe summary for confirmation;
6. attach the remote service to the user;
7. ensure one remote service belongs to only one customer;
8. transfer ownership only through authorized admin action;
9. create no fake payment history;
10. only allow compatible renewals/add-ons.

Apply SSRF controls and strict URL parsing.

## 16.5 Synchronization anomalies

Detect and alert:

- remote service missing;
- local service linked to wrong remote identity;
- one remote identity linked to multiple customers;
- unexpected data/expiry/status change;
- remote deletion;
- duplicate deterministic username;
- adapter capability change;
- panel version or auth failure.

## 16.6 Service notifications

Default configurable thresholds:

- expiry: 7, 3, and 1 days, and at expiry;
- data remaining: 20%, 10%, and exhausted;
- auto-renew failure;
- insufficient wallet balance;
- service suspended/deleted;
- panel sync issue when customer action is affected.

Each notification type, threshold, audience, and destination is independently enabled/disabled. Send each threshold once per service cycle and reset correctly after renewal/reset.

## 16.7 Service discovery, ownership, transfer, and reconciliation

Customer discovery:

- list and paginate owned services;
- search by service username, internal service ID, or order number;
- show last synchronized data when the panel is temporarily unavailable;
- allow each service's notifications to be enabled or disabled when policy permits.

Administrator discovery:

- search by Telegram ID, Telegram username, verified phone, internal user ID, order ID, payment ID, service username, remote panel ID, or transaction ID;
- open a unified customer/service view according to permission;
- attach an imported service to a customer after remote verification;
- transfer service ownership only through an explicit, audited operation;
- re-send service details without rotating credentials;
- initiate a synchronization or repair case.

Provide a structured reconciliation/repair workflow instead of unrestricted database-field editing. It may correct local ownership, offering reference, target reference, expiry snapshot, remote identifier, notification state, or delivery metadata only after validation against the remote panel and with before/after evidence. Financial history is never rewritten. A service record is retired with a reason rather than silently deleted when it must remain auditable.

## 16.8 Administrative service creation and batch grants

Authorized administrators may:

- create one complimentary/manual service for a selected customer;
- create multiple services in a controlled batch;
- add data, days, or both to one selected service;
- grant data, days, or both to a filtered group or all eligible active services on a selected server;
- preview affected count and estimated panel operations before confirmation;
- pause, resume, cancel, and retry a batch;
- notify each affected customer using localized text;
- export success/failure results.

These actions use explicit sources such as `admin_grant` or `campaign_grant`; they never create a fake payment. Every target service has its own idempotency key and result. Re-running a batch cannot apply the same grant twice.

## 16.9 Service operation policy details

For each offering and operation, define customer availability, administrator availability, price, discount eligibility, identity requirement, minimum/maximum values, cooldown, confirmation text, and adapter capability requirement.

Operations include:

- renew;
- add data;
- add days;
- combined data/days;
- reset usage;
- clear IP/session logs when supported;
- activate/suspend;
- rotate subscription token/link;
- refresh connection details;
- change protocol profile;
- change location/server;
- change plan;
- delete/retire remote service;
- synchronize and resend delivery data.

A paid operation must use the normal Quote -> Order -> Payment -> Provisioning path. A free administrator operation must use an audited administrative operation path.

---

# 17. Telegram Architecture and UX

## 17.1 Webhook

- use HTTPS webhook;
- validate Telegram webhook secret token;
- restrict method and content type;
- limit request body size;
- persist update ID idempotently;
- acknowledge quickly;
- process non-trivial work asynchronously;
- protect webhook routes from debug output and stack traces.

## 17.2 Conversation state

Do not encode arbitrary state and data in one string.

Use a conversation/session model with:

- user ID;
- flow name;
- explicit state enum;
- structured validated payload;
- version;
- created/updated/expiry time;
- optimistic lock/version;
- cancel/back behavior;
- safe recovery after deployment.

Flows must support `/cancel`, Back, timeout, and restart without corrupting an order/payment.

## 17.3 Callback data

Telegram callback data has strict size limits. Use short opaque callback tokens that resolve to server-side records.

Requirements:

- random unguessable token;
- action and actor binding;
- expiration;
- one-time or replay-safe semantics;
- permission and state re-check on execution;
- no sensitive data in callback payload;
- idempotent repeated click.

## 17.4 Buttons

Centralize button generation.

Support:

- reply keyboards;
- inline keyboards;
- default style;
- `primary` blue;
- `success` green;
- `danger` red;
- graceful fallback if a client does not render style.

## 17.5 Premium/custom emoji

Support:

- custom emoji entities in messages;
- `icon_custom_emoji_id` for supported buttons;
- admin captures ID by sending a sample;
- preview and test send;
- normal emoji fallback;
- capability failure does not break navigation.

Use only where Telegram account/bot eligibility permits and re-verify current Bot API behavior at implementation time.

## 17.6 File handling

- private storage;
- allowlisted MIME/type and extension;
- inspect actual MIME;
- size limits per use case;
- random storage names;
- no executable files;
- malware scan hook when available;
- safe media download through Telegram API;
- retention policy;
- authorized download route or Telegram resend;
- never expose storage paths.

## 17.7 Rate limits and retries

- respect Telegram `retry_after`;
- per-user and global rate limit;
- queue outbound messages;
- exponential backoff;
- distinguish blocked bot, chat not found, invalid content, and transient failure;
- store delivery attempts;
- do not retry permanent failures indefinitely.

## 17.8 Canonical customer navigation

The default Persian main menu must provide, subject to policies and permissions:

- Buy Subscription;
- Increase Wallet Balance;
- My Services;
- Trial Service;
- My Account;
- Client Applications and Connection Guides;
- Invite Friends;
- Support/Tickets;
- Import or Find Existing Service;
- Request Cooperation, or the Agent Menu after approval;
- administrator-defined custom buttons.

Canonical purchase workflow:

1. enforce account, membership, and identity policies;
2. choose service mode/category;
3. choose product/plan;
4. choose automatic or visible server according to offering policy;
5. choose protocol profile and username when enabled;
6. show an immutable price breakdown and applicable discount entry;
7. create the Order and list only eligible payment methods;
8. create one Payment Intent for the selected method;
9. wait for authoritative settlement;
10. enqueue provisioning;
11. deliver service details only after verified provisioning;
12. display an order tracking state throughout failures or delays.

Every multi-step workflow has Back, Cancel, expired-session handling, replay-safe callbacks, and a deterministic return to the correct menu.

## 17.9 Canonical agent navigation

The agent menu contains profile/status, single purchase, bulk purchase, wallet top-up, purchased services, service search, reports, and support. Agent pricing and gateway eligibility are resolved at quote time and snapshotted.

## 17.10 Canonical administrator navigation

Build a permission-filtered Telegram control center containing:

- dashboard and reports;
- customer search and management;
- orders, payments, receipts, refunds, and reconciliation;
- wallet and ledger;
- service search, creation, batch grants, and repair cases;
- categories, products, offerings, add-on packages, and pricing;
- panels, targets, servers, capacity, and health;
- payment methods, automatic verification providers, bank destinations, gift-card types, and rate sources;
- agents/reseller requests and pricing profiles;
- discounts, gift codes, referrals, and campaigns;
- tickets and support queues;
- direct message to one customer;
- broadcasts and message lifecycle jobs;
- localization, menus, custom buttons, and client-resource catalogue;
- required channels/groups and notification settings;
- logs, alerts, pending operations, Scheduler, workers, and health;
- backups, restore, updates, and rollback;
- administrators, roles, permissions, and system settings.

Each screen must implement pagination, search/filter where needed, permission checks on both display and execution, confirmation for sensitive changes, and Back/Cancel behavior.

---

# 18. Localization, Editable Text, Menus, and Custom Buttons

## 18.1 Localization

All user-facing text must be in language resources or content records, never scattered through business services.

Group keys by module:

- common/menu;
- onboarding/identity;
- catalog/purchase;
- payments;
- wallet;
- services;
- support;
- notifications;
- administration;
- errors/reports.

Default source language may be English for developer-maintained keys, with complete Persian translation shipped. The visible default is Persian.

## 18.2 Two-layer content model

- version-controlled default translations/templates;
- administrator override stored in database.

Features:

- view default and override;
- edit and preview;
- reset to default;
- version history;
- restore previous version;
- search by key/text;
- validate allowed placeholders;
- validate Telegram HTML/entity formatting;
- overrides survive updates.

## 18.3 Placeholder safety

Every template has an allowlist of placeholders. Escape user values before insertion. Reject unknown placeholders and invalid markup.

## 18.4 System buttons

System actions such as Buy, My Services, Wallet, Support, Back, and Cancel:

- label, emoji, style, order, and visibility may be configured;
- protected navigation actions cannot be re-bound to arbitrary behavior;
- disabling essential navigation must not trap users;
- preview before publish;
- menu versions and rollback.

## 18.5 Custom buttons

Allowed action types:

- show text/media;
- open URL;
- copy text where supported;
- open submenu;
- open support;
- show referral link;
- execute an allowlisted internal action;
- future Web App placeholder.

Settings:

- label;
- normal or premium emoji;
- style;
- row/order;
- parent menu;
- language;
- active date range;
- customer/agent/tier/tag rules;
- purchase-history rules;
- enabled state.

Never allow custom PHP, SQL, shell, arbitrary callback names, or untrusted URL schemes.

## 18.6 Mandatory localization namespaces

Seed complete Persian default copy and English fallback keys for at least:

- `onboarding.*`: welcome, start, membership, blocked, maintenance, phone request, verification success/failure;
- `identity.*`: Telegram contact, OTP, bank card, national ID, pending/verified/rejected, re-verification;
- `menu.*`: customer, agent, administrator, back, cancel, confirm;
- `catalog.*`: categories, products, servers, custom plans, trial, capacity, unavailable states;
- `quote.*`: price components, discounts, exact adjustment, expiry, confirmation;
- `payment.wallet.*`, `payment.card.*`, `payment.gift_card.*`, `payment.usdt.*`, `payment.zarinpal.*`, `payment.nowpayments.*`;
- `order.*`: created, awaiting payment, paid, provisioning, delayed, completed, canceled, review required;
- `service.*`: details, usage, expiry, QR, link delivery, renew, add-ons, state change, plan/location/protocol change, delete, import, transfer, sync;
- `agent.*`: application, review, approval, rejection, pricing, single/bulk purchase, reports;
- `wallet.*`: top-up, balance, transfer, hold, debit, credit, refund, correction;
- `promotion.*`: discount, gift code, referral, reward pending/released/reversed;
- `ticket.*`: create, category, assignment, reply, close, reopen, rating;
- `broadcast.*`: preview, schedule, progress, pause, resume, cancel, edit, pin, unpin, delete;
- `admin.*`: user search, receipt review, service repair, batch grant, reports, configuration;
- `alert.*`: customer-impacting, financial, security, integration, queue, backup, update;
- `installer.*`, `updater.*`, `backup.*`, and `error.*`.

Every key documents its allowed placeholders, parse mode, maximum length, and supported media/button context. Brand name, support ID, channel IDs, prices, addresses, and secrets are variables or settings rather than hard-coded copy.

---

# 19. Mandatory Channel/Group Membership

Support multiple channels/groups with:

- public/private;
- display title;
- join link;
- active state;
- all required or at least one required;
- order;
- re-check button.

Membership requirement can apply to:

- bot entry;
- trial;
- purchase;
- gift-code use;
- referral reward;
- ticket creation;
- specific offering/campaign;
- customer tier/tag.

Test bot access and membership-check capability when adding a channel. The bot must have required permissions.

Failure policy per rule:

- fail open;
- fail closed;
- manual review.

Recommended defaults:

- trial/gifts: fail closed;
- viewing paid services and support: fail open;
- purchase: configurable.

Normal non-membership does not alert administrators. Widespread Telegram API failure or misconfiguration does.

---

# 20. Support Tickets

## 20.1 Ticket states

- new;
- awaiting support;
- awaiting customer;
- investigating;
- resolved;
- closed.

## 20.2 Customer capabilities

- choose category;
- enter title and description;
- link order/payment/service;
- attach approved image/video/file;
- view history;
- reply;
- close;
- reopen within configurable window, default 72 hours;
- rate support after closure.

## 20.3 Support capabilities

- view queue;
- claim ticket;
- assign/transfer;
- reply with text/media;
- internal note invisible to customer;
- state and priority change;
- view customer data only according to permission;
- canned responses;
- close with reason;
- search by ticket/user/order/payment/service.

Categories seed:

- purchase and payment;
- technical service issue;
- renewal and data;
- account and wallet;
- agent/cooperation;
- other.

Categories are editable, sortable, routable to roles, and disableable.

## 20.4 Ticket security

- unique tracking number;
- customer can access only own tickets;
- private file storage;
- no permanent deletion of message history;
- duplicate reply prevention;
- rate limits;
- failed customer delivery is retried and alerted when material;
- new-ticket, SLA-delay, and delivery alerts are independently configurable.

Support contact display modes:

- internal tickets only;
- external support username only;
- both.

---

# 21. Broadcast Campaigns and Message Lifecycle

## 21.1 Campaign creation

Support:

- new message;
- forwarded message;
- copied message without source attribution;
- text, photo, video, animation, audio, document;
- inline and reply buttons where valid;
- colored buttons and premium emoji;
- preview;
- test-send to Owner;
- immediate or scheduled execution;
- pause, resume, cancel;
- progress and final report;
- retry failed recipients only.

Recommend copy mode when later editing is required.

## 21.2 Audience filters

- all users;
- customers or agents;
- tier;
- tags;
- with/without successful purchase;
- offering/category/server ownership;
- active/expired service;
- wallet balance range;
- channel membership state;
- manually selected users.

Show estimated recipient count before launch.

## 21.3 Recipient state

Store per campaign and user:

- queued;
- sending;
- sent with Telegram message ID;
- failed transient;
- failed permanent;
- skipped;
- edited;
- pinned;
- unpinned;
- deleted.

Unique constraints prevent duplicate send after worker restart.

## 21.4 Message lifecycle management

For each delivered message where Telegram allows:

- edit text/caption;
- edit buttons;
- remove buttons;
- replace media when supported;
- pin;
- unpin that message;
- unpin all only with explicit high-risk confirmation;
- delete;
- target all or selected recipients;
- pause/resume/cancel operation;
- progress and result counts;
- retry only failures;
- test operation on Owner first.

Store the actual Telegram message ID per recipient. Check current Bot API limitations before execution, including deletion/edit time restrictions and chat permissions.

## 21.5 Direct administrator-to-customer messaging

An authorized administrator can search and select one customer, preview the target identity, and send or copy:

- text;
- photo with caption;
- video;
- document;
- a forwarded message when Telegram permits;
- a copied message without source attribution;
- inline buttons from the safe button builder.

Require final confirmation showing the target Telegram ID and masked profile. Store sender administrator, target user, content type, Telegram result, message ID, correlation ID, and timestamp. Retrying after an uncertain response must use an idempotent outbound-message record. A user reply follows the normal ticket/support path unless an explicit supported conversation workflow is active.

---

# 22. Reporting and Operations Center

## 22.1 Reports

Provide permission-aware reports for:

- users and growth;
- customer tiers;
- agents;
- successful/failed/pending orders;
- revenue by period, gateway, product, category, and server;
- refunds;
- wallet movements;
- discounts and codes;
- referrals;
- active/expired services;
- server capacity and health;
- provisioning success/failure/SLA;
- payment-provider health;
- automatic/manual verification rates;
- card-to-card match success and ambiguity;
- gift-card validation/capture outcomes;
- ticket volume and SLA;
- broadcast delivery;
- system jobs and incidents.

Date ranges:

- today;
- yesterday;
- last 7/30 days;
- current/previous Persian month where implemented correctly;
- custom UTC-safe range displayed in Tehran time.

Financial reports derive from captured payments and ledger, not order status alone. Every figure must define currency, unit, refunds, exact adjustments, and timezone.

## 22.2 Privacy

- restrict reports by permission;
- mask phone, card, national ID, wallet address, and subscription link;
- exports are audited and expire;
- no secret in report;
- minimum necessary data.

## 22.3 Operations Center

Inside Telegram administration, provide:

- queue backlog;
- failed jobs;
- scheduler heartbeat;
- webhook health;
- panel health and version;
- payment provider health;
- SMS provider health;
- automatic card verification health/cursor;
- gift-card provider health;
- unmatched bank transactions;
- gift-card pending captures;
- recent critical alerts;
- backup status;
- release version;
- disk/database/Redis health;
- safe retry/reconcile actions according to permission.

---

# 23. Logging, Alerts, and Audit

## 23.1 Structured application logs

Use structured logs with:

- timestamp;
- severity;
- environment;
- module;
- event name;
- correlation ID;
- trace ID where useful;
- Telegram user ID/internal user ID when safe;
- order/payment/provisioning/service IDs;
- provider and operation;
- retry count;
- sanitized error class/code;
- duration.

Never log:

- bot token;
- database/Redis/provider passwords;
- OTP;
- full bank card, national ID, gift-card code, wallet private material;
- full subscription link;
- full receipt or identity document;
- raw Telegram Update by default;
- unredacted provider payload.

## 23.2 Alert levels

- info: stored internally;
- warning: internal plus report channel when operationally useful;
- critical: internal, report channel, and Owner private message;
- security: security audit plus Owner.

Customer-impacting and financial issues must alert, including:

- paid but provisioning failed;
- possible duplicate service;
- payment mismatch;
- automatic verification ambiguity spike;
- provider outage;
- suspicious duplicate receipt/code/TXID;
- wallet reconciliation failure;
- server capacity exhausted;
- delivery of service details failed;
- backup/restore/update failure;
- unauthorized action attempt.

## 23.3 Alert reliability

- persist alert before sending;
- retry transient send failure;
- aggregate repeated identical alerts to avoid flooding;
- preserve affected entity list;
- include a safe correlation/tracking code;
- alert message never contains secrets;
- record acknowledgement and resolution.

## 23.4 Immutable audit log

Audit at least:

- administrator login/use;
- role/permission changes;
- identity view/reveal/release;
- wallet correction;
- receipt approval/rejection;
- automatic verification configuration changes;
- manual override of automatic match;
- refund;
- panel/gateway/secret change;
- service ownership transfer;
- broadcast creation/edit/delete/pin;
- backup/restore;
- installer/updater/rollback;
- security policy changes.

Audit records are append-only for normal administrators.

---

# 24. Scheduler, Queues, Outbox, and Locks

## 24.1 Queues

Use separated queues or routing classes for:

- critical payments;
- bank transaction ingestion/matching;
- gift-card validation/capture;
- provisioning;
- Telegram delivery;
- broadcasts;
- synchronization;
- reports;
- backups;
- maintenance.

Critical payment/provisioning work must not be blocked behind large broadcasts.

## 24.2 Scheduled tasks

At minimum:

- expire quotes/payment intents/exact-amount reservations;
- poll bank transaction providers;
- reconcile bank transactions;
- reconcile gift-card validations/captures;
- process auto-renew;
- service sync and notification thresholds;
- panel/provider/SMS health checks;
- wallet/payment/order reconciliation;
- release pending referral rewards;
- continue broadcasts;
- backup;
- retention cleanup;
- customer tier recalculation;
- alert retry;
- scheduler heartbeat.

Every task has:

- distributed lock;
- overlap prevention;
- timeout;
- idempotency;
- run history;
- success/failure metrics;
- bounded retry;
- dead-letter/manual review path.

## 24.3 Transactional Outbox

Use an Outbox to guarantee that committed business events eventually produce external effects such as Telegram messages or jobs. The Outbox consumer is idempotent.

## 24.4 Locks

Use database uniqueness and transactions as the final safety barrier. Redis locks coordinate work but are not the sole financial correctness mechanism.

## 24.5 Heartbeats

Record scheduler and worker heartbeat. Alert when expected executions are missing.

---

# 25. Backup and Restore

## 25.1 Backup types

- database backup;
- application configuration backup excluding plaintext secrets where possible;
- private media metadata and optionally files according to policy;
- release manifest;
- checksum manifest.


- do not put password in command-line arguments;
- use a protected temporary client configuration or safe environment/descriptor mechanism;
- secure temporary files;
- validate exit code;
- compress;
- compute checksum;
- encrypt before external transfer;
- delete temporary plaintext reliably.

## 25.3 Encryption

- use authenticated encryption;
- backup key separate from backup file and repository;
- key rotation procedure;
- restoration requires authorized operator;
- never send unencrypted database backups to Telegram.

## 25.4 Telegram backup destination

If enabled:

- dedicated private channel/chat;
- verify bot permission;
- keep each uploaded part safely below current Bot API limit, for example 45 MB;
- split encrypted archive;
- send manifest with version, timestamp, part count, size, and checksums;
- verify all send results;
- do not mark backup successful until every required part is acknowledged;
- resumable retry;
- alert incomplete upload.

## 25.5 Retention defaults

Configurable defaults:

- daily: 7;
- weekly: 4;
- monthly: 6;
- release snapshots around updates.

## 25.6 Restore

Restore is a controlled workflow:

1. authorization and confirmation;
2. maintenance mode;
3. current-state safety backup;
4. verify manifest/checksum/decryption;
5. restore to isolated test database first when practical;
6. run integrity checks;
7. restore database/files;
8. run application checks and safe migrations;
9. resume workers/webhook;
10. verify financial, order, payment, and service consistency;
11. produce restore report.

A successful production release requires at least one tested restore rehearsal from a generated backup.

---

# 26. Secure Browser-Based Installer

## 26.1 Installer goals

The installer is the only browser UI required for initial setup. It must safely bootstrap the application without exposing secrets or allowing repeated unauthorized installation.

## 26.2 Installer flow

1. preflight both PHP runtimes, extensions, Composer availability, directories, permissions, OpenLiteSpeed document root, HTTPS, MariaDB, Redis, outbound access, disk space, and timezones;
2. create or validate release/shared directory layout;
3. collect database configuration and test connection;
4. collect Redis configuration and test authentication;
5. collect Telegram bot token through a secret field and validate with `getMe`;
6. collect Owner Telegram numeric ID;
7. collect report-channel/chat ID and test send when configured;
8. configure application URL, locale, `UTC`, and `Asia/Tehran` display timezone;
9. generate application key and internal signing/encryption keys;
10. write `.env` atomically with restrictive permissions;
11. run migrations and seed default roles, permissions, tiers, ticket categories, and settings;
12. create Owner administrator;
13. configure webhook with a random secret token;
14. perform queue/scheduler/Telegram/database/Redis health checks;
15. generate an installation report with no secrets;
16. create `installer.lock` and permanently disable normal installer access.

## 26.3 Installer security

- HTTPS required except explicit local test mode;
- unpredictable one-time setup token or temporary key;
- rate limit;
- CSRF protection;
- session fixation protection;
- no secret in URL;
- no secret echoed after submission;
- strict validation;
- fail safely without partial secret leakage;
- installer routes return 404/410 after lock;
- reopening requires an SSH command that creates a short-lived signed token;
- write operations are journaled for rollback;
- do not run Composer or arbitrary shell commands from user-provided values;
- use fixed allowlisted commands and arguments.

## 26.4 Bootstrap command

Provide an SSH bootstrap command/script that:

- verifies checksum/signature of release package;
- extracts to a new release directory;
- creates shared directories;
- sets correct ownership/permissions;
- links shared storage and `.env` placeholder;
- points OpenLiteSpeed document root to `current/public` through documented aaPanel steps;
- opens the installer only after safe preparation.

Do not require the owner to manually invent paths or commands.

---

# 27. Updater, Releases, and Rollback

## 27.1 Update package

Every release package includes:

- application source without secrets;
- `composer.lock`;
- migration files;
- release manifest;
- version;
- PHP/framework requirements;
- checksum list;
- signature or trusted checksum mechanism;
- release notes;
- migration/rollback compatibility metadata.

## 27.2 Update flow

1. authenticate authorized Owner;
2. upload or securely fetch package;
3. verify signature/checksum and manifest;
4. verify disk space and prerequisites;
5. create pre-update backup;
6. extract to a new immutable release directory;
7. install production dependencies as `www`, not root, with locked versions;
8. run static checks and package self-test;
9. link shared resources;
10. enter maintenance mode only when required;
11. pause or drain relevant workers;
12. run migrations with explicit strategy;
13. run health and smoke tests;
14. atomically switch `current` symlink;
15. reload/restart workers safely;
16. verify webhook, queue, scheduler, database, Redis, providers, and critical journeys;
17. exit maintenance;
18. produce update report;
19. keep previous releases according to retention.

## 27.3 Migration safety

Use expand/contract migrations when possible:

- add compatible schema first;
- deploy compatible code;
- backfill asynchronously;
- switch reads/writes;
- retire superseded schema elements only in a later compatible release.

Potentially destructive migrations require explicit backup, confirmation, and rollback/restore plan.

## 27.4 Rollback

- code rollback through symlink switch;
- database rollback only when migration explicitly supports it;
- otherwise restore from pre-update backup with documented data-loss window;
- prevent rollback to incompatible schema;
- test rollback in staging before production release.

## 27.5 Versioning

Use Semantic Versioning:

- patch: backward-compatible fixes;
- minor: backward-compatible features;
- major: breaking changes.

Maintain changelog, database schema version, release manifest, and installed version record.

---

# 28. Database Blueprint

The exact names may improve during design, but the following concepts and constraints are mandatory.

## 28.1 Identity and customers

- `users`
- `telegram_accounts`
- `customer_profiles`
- `customer_tiers`
- `customer_tier_history`
- `customer_tags`
- `customer_tag_assignments`
- `phone_numbers`
- `phone_verifications`
- `otp_challenges`
- `identity_items`
- `identity_reviews`
- `agent_profiles`
- `agent_pricing_profiles`
- `agent_applications`
- `agent_status_history`

Key constraints:

- Telegram user ID unique;
- normalized verified phone unique when active;
- encrypted sensitive values plus keyed hashes where lookup is required;
- explicit status enums;
- no polymorphic foreign key without enforced integrity strategy.

## 28.2 Access control

- `administrators`
- `roles`
- `permissions`
- `role_permissions`
- `administrator_roles`
- `administrator_permission_overrides`
- `sensitive_action_approvals`

Unique constraints prevent duplicate grants. Overrides use explicit inherit/allow/deny semantics.

## 28.3 Catalog and panels

- `categories`
- `products`
- `panel_connections`
- `panel_targets`
- `sales_servers`
- `plan_offerings`
- `offering_capabilities`
- `offering_fallbacks`
- `renewal_packages`
- `data_addon_packages`
- `day_addon_packages`
- `combined_addon_packages`
- `custom_plan_rules`
- `trial_policies`
- `server_capacity_snapshots`
- `panel_health_checks`
- `service_modes`
- `protocol_profiles`
- `delivery_policies`
- `client_resources`
- `client_resource_versions`

## 28.4 Orders and payments

- `quotes`
- `orders`
- `order_items`
- `order_price_components`
- `payment_methods`
- `payment_method_rules`
- `payment_destinations`
- `payment_intents`
- `payment_attempts`
- `provider_transactions`
- `provider_webhook_events`
- `manual_payment_submissions`
- `payment_reviews`
- `refunds`
- `exact_amount_reservations`

## 28.5 Automatic card-to-card verification

- `bank_verification_providers`
- `bank_provider_field_mappings`
- `bank_provider_sync_cursors`
- `bank_webhook_events`
- `bank_transactions`
- `bank_transaction_match_candidates`
- `bank_transaction_matches`
- `bank_reconciliation_runs`

Mandatory uniqueness:

- provider + external transaction ID;
- provider + webhook event ID;
- one consumed bank transaction -> at most one payment capture;
- active exact amount reservation unique within matching scope.

## 28.6 Gift-card verification

- `gift_card_types`
- `gift_card_verification_providers`
- `gift_card_provider_mappings`
- `gift_card_submissions`
- `gift_card_verification_attempts`
- `gift_card_redemptions`
- `gift_card_webhook_events`
- `gift_card_reconciliation_runs`

Mandatory uniqueness:

- keyed hash of code within relevant scope;
- provider + external redemption ID;
- one captured gift-card value cannot fund more than one payment unless explicit partial-capture model exists.

## 28.7 Wallet and ledger

- `ledger_accounts`
- `ledger_transactions`
- `ledger_entries`
- `wallet_balance_snapshots`
- `wallet_holds`
- `wallet_transfers`
- `financial_reconciliation_runs`

Every ledger transaction balances. Entries are append-only.

## 28.8 Provisioning and services

- `provisioning_operations`
- `provisioning_attempts`
- `service_subscriptions`
- `service_remote_identities`
- `service_sync_snapshots`
- `service_operations`
- `service_notification_states`
- `service_delivery_attempts`
- `service_imports`
- `service_ownership_transfers`
- `service_reconciliation_cases`
- `service_reconciliation_changes`
- `service_batch_grants`
- `service_batch_grant_items`
- `service_delivery_artifacts`

## 28.9 Promotions and referrals

- `discount_codes`
- `discount_code_rules`
- `discount_reservations`
- `discount_redemptions`
- `gift_codes`
- `gift_code_redemptions`
- `referrals`
- `referral_reward_rules`
- `referral_rewards`

## 28.10 Support, broadcast, and content

- `ticket_categories`
- `tickets`
- `ticket_messages`
- `ticket_attachments`
- `ticket_assignments`
- `ticket_internal_notes`
- `ticket_ratings`
- `broadcast_campaigns`
- `broadcast_audiences`
- `broadcast_recipients`
- `broadcast_message_versions`
- `broadcast_recipient_messages`
- `content_overrides`
- `content_versions`
- `menu_definitions`
- `menu_versions`
- `custom_buttons`
- `required_channels`
- `channel_membership_rules`
- `direct_customer_messages`
- `direct_customer_message_attempts`

## 28.11 Operations

- `outbox_messages`
- `processed_telegram_updates`
- `idempotency_keys`
- `scheduled_task_runs`
- `worker_heartbeats`
- `alerts`
- `alert_deliveries`
- `audit_logs`
- `integration_health_checks`
- `backups`
- `backup_parts`
- `restore_runs`
- `releases`
- `update_runs`
- `system_settings`
- `secret_references`

## 28.12 Schema rules

- use foreign keys where relational integrity exists;
- use unique indexes for idempotency and one-to-one business constraints;
- use integer IRR and fixed decimal crypto;
- use UTC timestamps;
- use soft delete only where business semantics require it;
- financial/audit records are not deleted;
- use check constraints where supported and application validation as defense in depth;
- index all operational lookup paths;
- test query plans for large broadcast, transaction, service, and log tables;
- partition/archive only when measured scale requires it.

---

# 29. Security Baseline

## 29.1 Threat model

Document threats for:

- forged Telegram webhook;
- callback replay;
- administrator impersonation;
- horizontal/vertical privilege escalation;
- duplicate payment/callback/webhook;
- forged manual receipt;
- malicious bank/gift provider payload;
- SSRF and DNS rebinding;
- gift-card code theft;
- OTP abuse;
- SQL injection;
- XSS in installer/updater;
- Telegram HTML/Markdown injection;
- file upload abuse;
- secret leakage;
- log leakage;
- panel MITM;
- compromised provider;
- queue replay;
- race conditions;
- malicious update package;
- backup theft;
- restore tampering;
- supply-chain compromise.

## 29.2 Controls

- default deny authorization;
- least privilege;
- CSRF for browser UI;
- Telegram user/chat binding;
- signed one-time sensitive actions;
- optional dual approval for high-value operations;
- encryption at rest for secrets and identity/payment codes;
- keyed hashes for searchable sensitive identifiers;
- TLS verification;
- SSRF egress restrictions;
- strict file validation;
- webhook HMAC/signature verification;
- replay/idempotency protection;
- request size/time limits;
- rate limits;
- secure random values;
- dependency audit and lockfile;
- no debug mode in production;
- security headers for web pages;
- restrictive filesystem permissions;
- secret rotation procedures;
- audit trails;
- backups encrypted;
- signed release packages.

## 29.3 Secret lifecycle

- secrets are entered only through installer or protected admin action;
- stored in `.env` or encrypted secret store/reference;
- never returned in full after save;
- test action uses secret without exposing it;
- rotation is supported;
- superseded secret invalidated after the verified switch;
- secrets excluded from backups unless encrypted through a separate key policy;
- revoke all test credentials before production.

## 29.4 SSRF

All administrator-configurable external endpoints must:

- require HTTPS;
- validate hostname and port;
- block IP literals unless explicitly approved;
- resolve DNS and block private/reserved/link-local/metadata ranges;
- re-check redirects and every resolved target;
- restrict redirect count;
- optionally enforce domain allowlist;
- limit response size/time;
- never allow `file://`, `gopher://`, `ftp://`, Unix sockets, or local paths.

## 29.5 TLS

Never disable certificate or hostname verification. For private/self-signed systems, use a managed custom CA or pinned certificate with documented rotation.

---

# 30. Test Strategy and Mandatory Quality Gates

## 30.1 Principles

- tests are written before or with features;
- every requirement ID maps to one or more tests;
- financial and authorization invariants have concurrency/property tests;
- external integrations have fake, contract, and sandbox/controlled live tests;
- integration tests use real MariaDB and Redis;
- time uses an injectable Clock;
- randomness uses an injectable generator;
- tests are deterministic;
- no production claim without evidence.

## 30.2 Unit tests

Cover at least:

- IRR/Toman conversion;
- crypto decimals and rounding;
- customer tier calculation;
- gateway rule engine;
- price engine;
- discount/referral/agent price;
- state transitions;
- permission role merge and deny precedence;
- phone normalization;
- OTP limits;
- notification thresholds;
- fallback selection;
- exact amount generation;
- bank transaction normalization and matching;
- gift-card status mapping and auto-approval policy;
- content placeholder validation;
- masking and keyed hashing.

## 30.3 Database and integration tests

- fresh migrations;
- migration rollback/compatibility where promised;
- foreign keys, unique indexes, and checks;
- ledger balance;
- wallet race conditions;
- exact amount reservation collision and expiry;
- discount reservation race;
- capacity race;
- duplicate update/callback/webhook;
- bank transaction consumption uniqueness;
- gift-card code/redemption uniqueness;
- Outbox commit and delivery;
- queue retry/dead letter;
- encryption casts;
- query/index performance.

## 30.4 Common payment test matrix

For every provider:

- create success/failure/timeout;
- invalid configuration;
- callback/webhook valid and invalid signature;
- duplicate and out-of-order event;
- amount/currency/order mismatch;
- expired intent;
- late payment;
- under/over/partial payment;
- provider unavailable;
- success response lost;
- authoritative status differs from callback;
- already verified/captured;
- full/partial/duplicate refund;
- no provisioning before capture.

## 30.5 Card-to-card automatic verification tests

- pull API pagination/cursor;
- webhook signature and replay;
- IRR/Toman unit mapping;
- timestamp timezone mapping;
- exact unambiguous match;
- no match;
- multiple candidate intents;
- multiple candidate transactions;
- wrong destination;
- wrong amount;
- late transaction;
- duplicate provider transaction;
- transaction reversal after capture;
- sender-card optional match;
- provider timeout with uncertain result;
- circuit breaker/manual fallback;
- two workers match same transaction concurrently;
- manual and automatic approval race;
- maximum auto-approval amount;
- SSRF/DNS rebinding attempts against generic provider;
- malicious/oversized JSON;
- secret redaction.

## 30.6 Card-to-card manual tests

- same receipt submitted twice;
- identical Telegram file reused;
- two admins approve concurrently;
- rejection reason required;
- approval ceiling;
- exact-amount collision;
- late receipt;
- link receipt to normalized transaction;
- override requires audit.

## 30.7 Gift-card automatic verification tests

- valid code with atomic capture;
- valid but unreservable code goes to manual review;
- invalid code;
- already redeemed;
- wrong brand/region/currency/value;
- pending provider result;
- duplicate code hash;
- validation response lost;
- capture response lost then status reconciliation;
- two workers redeem concurrently;
- partial value unsupported;
- image-capable provider;
- image-only without authoritative extraction goes to manual review;
- webhook signature/replay;
- provider outage/manual fallback;
- malicious response/SSRF;
- code masking and log redaction.

## 30.8 Provisioning test matrix

- create success;
- definitive validation failure;
- timeout before remote create;
- timeout after remote create;
- remote 5xx but service created;
- duplicate queued job;
- worker crash after remote create before local commit;
- reconciliation finds existing service;
- capacity full;
- fallback enabled/disabled;
- incompatible fallback;
- panel auth failure;
- service missing;
- Telegram delivery failure;
- retry exhaustion/manual review.

## 30.9 Wallet and concurrency tests

- simultaneous purchases with same balance;
- auto-renew versus manual purchase;
- transfer versus purchase;
- double hold/capture/release;
- duplicate refund;
- correction/reversal;
- no negative balance;
- ledger balance after every scenario.

## 30.10 Telegram E2E tests

- start/onboarding;
- language/menu;
- phone contact and OTP;
- purchase flow;
- each payment method;
- manual review;
- service delivery;
- trial;
- renewal/add-ons;
- wallet transfer;
- discount/referral;
- ticket lifecycle;
- broadcast lifecycle;
- permission denial;
- Back/Cancel/timeout;
- duplicate click/update;
- colored button and custom emoji graceful fallback.

## 30.11 Security tests

- forged webhook/callback;
- authorization bypass;
- IDOR;
- CSRF installer/updater;
- session fixation;
- SQL injection;
- XSS/Telegram markup injection;
- SSRF;
- DNS rebinding;
- file upload bypass;
- path traversal;
- secret scanning;
- log leakage;
- insecure TLS rejection;
- malicious update package;
- backup tamper/decryption failure;
- rate-limit/OTP abuse;
- permission cache invalidation.

## 30.12 Performance and load tests

Establish documented target load and test:

- webhook bursts;
- concurrent purchases;
- exact-amount allocation;
- bank provider transaction ingestion;
- large broadcast;
- service synchronization;
- report queries;
- queue backlog recovery;
- database connection limits;
- Redis outage/degradation behavior.

Do not invent arbitrary impressive numbers. Define realistic expected volume with the owner before final load certification, while still running baseline tests with documented assumptions.

## 30.13 Chaos and failure tests

- Telegram unavailable;
- Redis restart;
- queue worker killed mid-job;
- database transient deadlock;
- panel timeout;
- payment provider timeout;
- bank provider sends duplicate/reordered transactions;
- gift-card provider captures but response is lost;
- disk nearly full;
- backup upload interrupted;
- deployment interrupted before/after symlink switch.

## 30.14 Backup, installer, and update tests

- clean install on target-like server;
- invalid prerequisite;
- installer replay denied;
- secret not exposed;
- update success;
- failed migration;
- failed smoke test;
- symlink rollback;
- incompatible rollback blocked;
- encrypted backup generation;
- split Telegram upload;
- full restore rehearsal;
- post-restore financial reconciliation.

## 30.15 Functional workflow regression tests

Automate and record at least:

- customer start, referral attribution, membership gate, contact verification, OTP verification, and re-verification;
- standard purchase, custom plan, trial, agent single purchase, and agent bulk purchase;
- agent application, approval, rejection, reapplication policy, suspension, and restoration;
- client application/guide catalogue visibility and link changes;
- service search, import, attach, ownership transfer, resend, notification toggle, and reconciliation repair;
- renewal, add data, add days, combined add-on, reset usage, state change, link rotation, plan/location/protocol change, and deletion;
- administrator single-service grant, multi-service creation, and resumable batch data/day grant;
- direct administrator-to-customer text/media delivery;
- customer and administrator menu snapshots under multiple permission/policy combinations.

## 30.16 Static release gates

Block release on:

- failing tests;
- formatter or static-analysis failure;
- high/critical dependency vulnerability without approved mitigation;
- detected secret;
- `verify=false` or equivalent;
- forbidden raw SQL concatenation;
- monetary float;
- unlocalized user-facing text outside allowed locations;
- missing requirement test evidence;
- unreviewed migration;
- unknown license issue.

---

# 31. Execution Phases

## `0.1.0` — Product Specification and Architecture

Deliver:

- stable requirement-ID index in `docs/01-authoritative-requirements.md`;
- domain glossary;
- use cases;
- state machines;
- schema ERD;
- permission catalog;
- threat model;
- test strategy;
- module boundaries;
- ADRs;
- risk register;
- GitHub-linked requirement coverage through owning Issues/PRs, code/tests, review, and applicable CI.

Gate: every requirement in this prompt is mapped and no unresolved Critical business ambiguity remains.

## `0.2.0` — Foundation and Installer Skeleton

Deliver:

- Laravel project;
- module skeleton;
- CI/static tooling;
- config/secrets framework;
- installer preflight and locked skeleton;
- health framework;
- Outbox/idempotency foundations;
- base migrations;
- deployment layout scripts.

Gate: clean install in disposable target-like environment and mandatory static tests pass.

## `0.3.0` — Identity, Customers, Agents, and ACL

Deliver:

- users/Telegram accounts;
- customer tiers/tags;
- agent profile, application, review, approval/rejection, suspension, and status history;
- roles/permissions/overrides;
- phone contact verification;
- OTP with Melli Payamak/Kavenegar adapters and fakes;
- identity items;
- audit foundations.

Gate: authorization matrix, OTP abuse tests, and identity privacy tests pass.

## `0.4.0` — Catalog, Panels, and Offerings

Deliver:

- categories/products/offerings;
- servers/targets/capabilities/capacity;
- Marzban/PasarGuard adapters and fakes;
- connection tests;
- custom plan, trial, fallback, and package configuration.

Gate: adapter contract tests and remote idempotency tests pass.

## `0.5.0` — Ledger, Pricing, Promotions, and Payment Providers

Deliver:

- balanced ledger and wallet;
- pricing/discount/referral/agent pricing;
- payment intents;
- wallet, card-to-card, gift card, USDT, Zarinpal, NOWPayments;
- automatic card provider foundation;
- automatic gift-card provider foundation;
- manual review;
- rate sources;
- reconciliation jobs.

Gate: all payment, matching, redemption, wallet, concurrency, and no-provision-before-payment tests pass.

## `0.6.0` — Orders, Provisioning, and Service Lifecycle

Deliver:

- order state machine;
- provisioning orchestration;
- service creation/delivery;
- renewals/add-ons/reset;
- auto-renew;
- import and synchronization;
- notification thresholds.

Gate: failure/uncertain-result matrix and duplicate-service prevention pass.

## `0.7.0` — Telegram UX, Support, Content, Membership, Broadcast

Deliver:

- complete Persian bot UX;
- localization overrides;
- menus/buttons/custom emoji;
- mandatory channels;
- tickets;
- broadcast creation, delivery, edit, pin, unpin, delete;
- rate-limit behavior.

Gate: Telegram E2E journeys and broadcast idempotency pass.

## `0.8.0` — Reporting, Operations, Backup, Updater

Deliver:

- permission-aware reports;
- Operations Center;
- structured alerts/audit;
- encrypted backup and Telegram parts;
- restore flow;
- updater/rollback;
- worker and scheduler deployment files.

Gate: restore rehearsal, update/rollback rehearsal, and operations failure tests pass.

## `0.9.0` — Hardening and Release Candidate

Deliver:

- full regression;
- security review;
- performance baseline;
- chaos tests;
- dependency/license audit;
- documentation completion;
- production rehearsal;
- `0.9.0-rc.1` package.

Gate: no Critical/High known defect and all release gates pass.

## `1.0.0` — Production Release

Deliver:

- signed/checksummed package;
- install and update runbooks;
- final source;
- final database schema;
- final test/security/restore reports;
- release notes;
- owner handover;
- production deployment checklist;
- post-deployment verification checklist.

---

# 32. Owner Inputs Requested Only When Needed

The team may request, at the exact integration/deployment phase:

- Telegram bot token;
- Owner Telegram numeric ID;
- report channel/chat ID;
- MariaDB application database credentials;
- Redis password;
- Marzban test and production endpoints/credentials;
- PasarGuard test and production endpoints/credentials;
- Zarinpal sandbox/live credentials;
- NOWPayments sandbox/live credentials and IPN secret;
- Melli Payamak credentials/template;
- Kavenegar credentials/template;
- USDT BEP20 destination wallet;
- optional blockchain API credentials;
- card-to-card destination bank data;
- actual automatic bank transaction provider API details when available;
- actual gift-card verification provider API details when available;
- report-channel and backup-channel permissions;
- backup encryption key setup;
- business values not fixed in this prompt, such as high-value approval thresholds and final load targets.

## 32.1 Secret collection method

Never ask the owner to paste secrets into a normal chat message. Provide one of:

- installer secret input;
- exact `.env` key and a safe SSH editor command;
- interactive Artisan command with hidden input;
- temporary protected secret file with permissions and deletion steps.

Always provide verification without printing the secret.

---

# 33. Communication with the Owner

Proceed without asking when:

- a safe default is defined here;
- a fake/sandbox can unblock development;
- an implementation detail can be decided by engineering judgment without changing business or financial behavior.

Stop and ask only when:

- a real credential/access is required;
- a root/production action is required;
- a business policy with financial/legal impact is genuinely unspecified;
- an irreversible destructive migration is proposed;
- a real provider behaves differently from its official contract and the choice affects money/security.

Use this question format:

- exact blocker;
- why it cannot be resolved safely;
- recommended option;
- alternatives and consequences;
- exact value/action needed;
- safe method to provide it.

Do not ask multiple unrelated questions in one blocker unless they are all required for the same immediate step.

---

# 34. Final Documentation and Deliverables

## 34.1 Code and package

- complete Git repository;
- tagged releases;
- production package;
- checksums/signature;
- `composer.lock`;
- migrations/seeders;
- worker/service configs;
- installer/updater;
- no secrets.

## 34.2 Documentation

At minimum:

- architecture overview;
- module map;
- ERD/data dictionary;
- state machines;
- permission catalog;
- payment provider guide;
- automatic card verification provider guide;
- gift-card provider guide;
- panel adapter guide;
- Telegram UX guide;
- administrator manual;
- support manual;
- installation guide for aaPanel/OpenLiteSpeed;
- update/rollback guide;
- backup/restore guide;
- incident runbook;
- secret rotation guide;
- troubleshooting guide;
- development environment guide;
- coding standards;
- API/integration contract docs;
- changelog.

## 34.3 Evidence

- requirement coverage report;
- test report;
- coverage report with meaningful interpretation;
- static-analysis report;
- dependency/security/license audit;
- payment sandbox/contract evidence;
- panel contract evidence;
- concurrency evidence;
- load baseline;
- backup/restore rehearsal report;
- update/rollback rehearsal report;
- final known-risks register.

---


# 35. Authoritative Implementation References

Use the following official sources to verify current endpoint schemas, signatures, limits, status values, and version compatibility at implementation time. Product behavior and acceptance criteria remain defined by this specification.

- Laravel 13 documentation: `https://laravel.com/docs/13.x`
- Telegram Bot API: `https://core.telegram.org/bots/api`
- Marzban API documentation and the installed panel's `/docs` OpenAPI endpoint: `https://gozargah.github.io/marzban/en/docs/api`
- PasarGuard documentation: `https://docs.pasarguard.org/en/`
- Zarinpal documentation: `https://www.zarinpal.com/docs/`
- NOWPayments OpenAPI: `https://nowpayments.io/doc/nowpayments-openapi.json`
- Melli Payamak API: `https://www.melipayamak.com/api/`
- Kavenegar REST API: `https://kavenegar.com/rest.html`
- Nobitex API: `https://apidocs.nobitex.ir/`
- Wallex public markets endpoint: `https://api.wallex.ir/hector/web/v1/markets`
- PHP 8.4 manual: `https://www.php.net/manual/en/`
- MariaDB documentation: `https://mariadb.com/docs/`
- Redis documentation: `https://redis.io/docs/latest/`
- OpenLiteSpeed documentation: `https://docs.openlitespeed.org/`
- OWASP ASVS, API Security, and Cheat Sheet Series: `https://owasp.org/`

For each integration that enters Version 1, maintain or refresh the smallest applicable canonical contract reference containing the tested version, endpoint base URL, authentication method, request/response samples with secrets removed, timeout/retry policy, status mapping, rate limits, webhook signature verification, and contract-test evidence. Keep task-specific progress, review discussion, and CI history in GitHub; do not create a separate note merely to duplicate that dynamic state.

---

# 36. Functional Requirement and Workflow Catalogue

The team must use these stable IDs in the owning GitHub Issue/PR, code/tests, and acceptance review where useful; do not copy them into a mutable traceability matrix. Every item requires design, implementation, automated tests, and acceptance evidence.

| ID | Required capability and canonical acceptance workflow |
|---|---|
| `ONB-001` | First `/start` creates or updates one Telegram identity, preserves immutable Telegram ID, records referral payload safely, and shows the correct localized menu. Duplicate updates are idempotent. |
| `ONB-002` | Referral attribution is accepted only on the first eligible start, cannot point to self, is locked after the first successful purchase, and is auditable. |
| `ONB-003` | Required channel/group membership can be enforced globally or per action. The bot checks membership, shows join links, supports retry, and applies configured fail-open/fail-closed behavior during API failure. |
| `ONB-004` | Phone verification supports Telegram contact, OTP, either, or both. Iranian numbers are normalized, uniqueness is enforced, and administrator-triggered re-verification invalidates the prior proof. |
| `ONB-005` | Blocked, suspended, limited, and maintenance states show safe messages and prevent protected actions while preserving access to configured support/service views. |
| `USR-001` | My Account shows Telegram ID, profile, tier, account type/status, masked verified phone, wallet buckets, referral information, and relevant dates without exposing secrets. |
| `USR-002` | Customer tiers `new`, `normal`, `loyal`, and `vip` recalculate from configurable rules; manual override/lock and all changes are audited. |
| `USR-003` | Administrators manage customer status and tags with permissions, confirmation, reason, and before/after audit evidence. |
| `AGT-001` | A customer submits one active cooperation request, sees its status, and cannot create duplicates. |
| `AGT-002` | Authorized review supports claim, approve, reject with reason, release for reapplication, suspend, and restore. Approval atomically assigns an agent profile/pricing profile. |
| `AGT-003` | Agent single purchase uses agent quote rules, eligible gateways, normal payment integrity, and stores the resulting service in the agent's purchased-services list. |
| `AGT-004` | Agent bulk purchase creates one parent order and idempotent child items; successful children are never recreated when another child fails. |
| `AGT-005` | Agent pricing resolves most-specific product/server/offering/action price, snapshots the calculation, and obeys configured discount-code combination rules. |
| `AGT-006` | Agent profile/report shows approval date, purchase count, spending/sales metrics, and purchased services within permission and date filters. |
| `ACL-001` | One Telegram administrator may hold multiple roles; permission calculation combines roles with per-user inherit/allow/deny overrides and explicit deny precedence. |
| `ACL-002` | Every administrator action re-checks permission server-side; hidden buttons are never treated as authorization. |
| `ACL-003` | Sensitive financial, credential, ownership, restore, update, and large batch actions require confirmation and optionally second approval according to policy. |
| `CAT-001` | Administrators create, edit, order, activate, and archive localized categories without deleting historical order references. |
| `CAT-002` | Products/plans define commercial identity; Plan Offerings define server-specific price, duration, data, capacity, target, capabilities, and eligibility. |
| `CAT-003` | Shared, dedicated/individual, and future service modes are typed offering attributes with editable customer labels. |
| `CAT-004` | Protocol profiles and panel targets are validated typed configurations. Customer protocol selection appears only when enabled and supported. |
| `CAT-005` | Custom plans enforce min/max/step rules for data and days, calculate snapshotted price components, and validate the username before payment and provisioning. |
| `CAT-006` | Trial service is a zero-cost order source with configurable volume, days, capacity, eligibility, identity/membership requirements, fallback, and abuse limits. |
| `CAT-007` | The client application/guide catalogue provides localized, ordered, policy-filtered download and tutorial links; administrators can add, edit, disable, and audit resources. |
| `CAT-008` | Capacity and health rules can stop sale, recover according to policy, and select only compatible fallback servers disclosed to the customer. |
| `BUY-001` | Standard purchase follows policy checks -> category/mode -> plan -> server -> optional protocol/username -> quote -> discount -> gateway -> payment -> provisioning -> delivery. |
| `BUY-002` | Every quote includes base price, account/agent override, discount, final price, currency, validity, and immutable configuration snapshot. |
| `BUY-003` | Back, Cancel, timeout, repeated button, and resumed session behavior are deterministic in every purchase flow. |
| `PAY-001` | The gateway rule engine returns visible/eligible methods based on account type, tier, tags, age, history, amount, action, offering, identity, time, limits, and health. |
| `PAY-002` | One Order can have controlled Payment Intents, but one captured settlement completes it. Provider return pages alone never prove payment. |
| `PAY-003` | Duplicate callbacks, webhooks, Telegram updates, manual approvals, and queue retries return the prior result without a second capture or provisioning. |
| `C2C-001` | Card-to-card creates an expiring exact IRR amount with a configurable 100-999 Toman upward adjustment, unique within destination/time scope; the adjustment is part of the paid amount and is not refundable. |
| `C2C-002` | Manual card-to-card accepts private receipt evidence, queues review, requires authorized approve/reject with reason, and prevents two reviewers from creating two effects. |
| `C2C-003` | Automatic card-to-card works through Fake and Generic REST providers with polling and/or signed webhook ingestion, normalized transactions, cursors, deduplication, health, and circuit breaker. |
| `C2C-004` | Matching requires settled status, correct destination, exact amount, valid time window, unique external transaction, and unambiguous candidate. Optional sender identity rules may strengthen the match. |
| `C2C-005` | Ambiguous, mismatched, reversed, duplicate, stale, provider-error, or high-risk transactions enter manual review; scheduled reconciliation detects later status changes. |
| `GFT-001` | Gift-card submission supports image, code, either, or both according to gift-card type policy; codes are normalized and stored as keyed hashes plus encrypted value only when necessary. |
| `GFT-002` | Manual gift-card review supports approve/reject with reason, private attachments, reviewer limits, and idempotent capture. |
| `GFT-003` | Automatic gift-card verification works through Fake and Generic REST providers and separates validate, reserve, redeem/capture, release, and balance/status checks. |
| `GFT-004` | A code or external redemption cannot fund two payments. Unknown, already-used, wrong-value, wrong-region, pending, or provider-error responses never auto-capture and are reconciled/manual-reviewed. |
| `USDT-001` | Direct USDT displays BEP20 clearly, locks an IRR-to-USDT quote, destination address, exact decimal amount, source rate, margin, and expiration. |
| `USDT-002` | Rate providers include managed Manual IRR-per-USDT, Nobitex public USDT/RLS, and Wallex public `USDTTMN`, with deterministic `Nobitex -> Wallex -> Manual` selection, stale-rate limits, min/max sanity checks, divergence guard, circuit breaking, protected managed-setting history, and test connection. |
| `USDT-003` | Customer submits TXID and optional evidence; TXID uniqueness, network, destination, amount, confirmations, and time are reviewed manually and remain adapter-ready for automatic chain verification. |
| `IPG-001` | Zarinpal request, redirect, callback, server-side verify, amount/authority matching, duplicate verify handling, refund capability detection, and reconciliation are implemented against official docs. |
| `IPG-002` | NOWPayments implements create-payment, IPN signature verification, server-side status lookup, amount/currency/order matching, partial/over/under payment policy, expiration, and reconciliation. Version 1 pricing uses the same snapshotted IRR-per-USDT authority as direct USDT as the explicit `price_currency=usd` pricing proxy; no separate USD/IRR provider or Manual USD authority exists. |
| `WAL-001` | Wallet top-up uses a normal Payment Intent; a successful external settlement creates one balanced ledger transaction and updates the cash balance. |
| `WAL-002` | Cash and promotional-credit buckets use append-only double-entry ledger transactions, holds, capture/release, balance snapshots, and reconciliation. |
| `WAL-003` | User-to-user transfer validates recipient, limits, status, transferable bucket, fee, and confirmation, then posts debit/credit atomically. |
| `WAL-004` | Refund supports partial amounts and method-specific destination; total refunds cannot exceed refundable value and exact card adjustment is excluded. |
| `WAL-005` | Administrator balance correction requires permission, amount, bucket, reason, preview, confirmation, optional second approval, and a compensating ledger entry. |
| `PRV-001` | Marzban and PasarGuard connections can be created, tested, versioned, health-checked, and mapped to targets without exposing credentials. |
| `PRV-002` | Provisioning uses a deterministic operation key, checks remote state before retry, verifies the final remote object, and cannot create two services for one order item. |
| `PRV-003` | Panel timeout after a successful remote create triggers discovery/reconciliation before another create request. |
| `SVC-001` | My Services lists and searches owned services with state, data, usage, remaining data, expiry, last sync, server, plan, and allowed actions. |
| `SVC-002` | Delivery supports subscription link, selected individual configs, QR, configurable threshold, and secure re-send; secrets never enter logs or alerts. |
| `SVC-003` | Renewal, add data, add days, and combined add-on use separate configured packages and the full quote/payment/provisioning path. |
| `SVC-004` | Reset usage, clear supported IP/session logs, activate/suspend, refresh details, and rotate subscription link are capability- and policy-gated. |
| `SVC-005` | Change plan, location/server, or protocol profile validates compatibility, price difference policy, remote result, and delivery update. |
| `SVC-006` | Customer deletion/retirement requires warning and confirmation, performs the remote action once, records non-refund policy, and preserves audit/financial history. |
| `SVC-007` | Auto-renew uses wallet hold/capture once, configured package, price-change policy, and success/insufficient/failure notifications. |
| `SVC-008` | Import existing service accepts a subscription link only from registered service domains, applies SSRF-safe parsing, verifies remote account, previews it, and attaches it without fake payment history. |
| `SVC-009` | Authorized service ownership transfer is explicit, validated, auditable, and not performed by editing a user ID field directly. |
| `SVC-010` | Service repair/reconciliation compares local and remote state, presents before/after changes, corrects only allowed metadata, and never rewrites financial history. |
| `SVC-011` | Authorized administrators can create complimentary/manual services and multi-service batches with explicit source and no fake payment. |
| `SVC-012` | Data/day batch grants support preview, filters, per-service idempotency, pause/resume/cancel, progress, customer notification, and result export. |
| `SVC-013` | Per-service and global expiry/usage/auto-renew/state notifications are independently enabled, thresholded, sent once per cycle, and reset correctly. |
| `SVC-014` | An authorized administrator can re-send current service details without rotating identifiers. |
| `PRO-001` | Discount codes support fixed/percent, caps, min amount, dates, total/user limits, first purchase, audience, offering/action/gateway scope, reservation, redemption, and release. |
| `PRO-002` | Gift codes support promotional credit, service grant, or discount; codes can be generated individually/batch, expire, be scoped, disabled, and audited. |
| `REF-001` | Referral link, inviter lock, reward rules, pending release, anti-abuse, refund reversal, limits, and user notifications work idempotently. |
| `SUP-001` | Customer creates a categorized ticket with title, description, related order/payment/service, and safe attachments, then receives a unique tracking number. |
| `SUP-002` | Support queue supports claim, assignment, transfer, priority, status, response, internal note, canned response, search, close reason, reopen window, and rating. |
| `COM-001` | Authorized direct messaging to one customer supports text/media/forward/copy/buttons, target preview, confirmation, idempotent delivery record, and result logging. |
| `COM-002` | Broadcast supports target filters, preview, Owner test, immediate/scheduled start, pause/resume/cancel, progress, and exactly-once recipient state. |
| `COM-003` | Sent broadcast messages support edit, button change, pin, unpin, delete, and retry failed recipients where Telegram permits, with per-recipient result. |
| `CNT-001` | All customer-visible and administrator-visible copy is in localization keys with Persian defaults, English fallback, documented placeholders, validation, version history, preview, and reset. |
| `CNT-002` | System menu labels/order/style are configurable within safety rules; custom buttons use only registered actions, safe text/media, HTTPS URL, copy text, submenu, or support/referral actions. |
| `CNT-003` | Colored button styles and premium emoji are previewed, stored with normal emoji fallback, and degrade safely on unsupported clients. |
| `CHN-001` | Multiple required channels/groups and all/any membership rules can be configured per action, audience, date, and failure policy. |
| `ADM-001` | Administrator search resolves users, orders, payments, services, TXIDs, and receipt/gift submissions, while masking fields outside the viewer's permission. |
| `ADM-002` | Administrators, roles, permissions, multi-role assignment, overrides, ownership transfer, enable/disable, and audit are fully managed in Telegram. |
| `REP-001` | Reports cover users, gross/net sales, discounts, refunds, exact adjustments, wallet liability, gateways, catalog, services, panels, agents, tickets, referrals, broadcasts, and pending failures. |
| `REP-002` | Date ranges include today, yesterday, 7/30 days, current week/month, Persian month, 3/6 months, one year, all time, custom range, and prior-period comparison. |
| `REP-003` | Reports can display in Telegram, export CSV/XLSX, send to a report channel, and run on configurable schedules with permission checks. |
| `OPS-001` | Structured logs, correlation IDs, alert severity, deduplication, retrying alert delivery, private Owner alert, report channel alert, and immutable audit exist without secret leakage. |
| `OPS-002` | Operations Center lists pending receipts, provisioning failures, unmatched transactions, reconciliation differences, panel/gateway failures, stalled jobs, unread alerts, and worker/Scheduler health. |
| `OPS-003` | One Cron runs Laravel Scheduler; Redis queues use separated priorities, Supervisor workers, DB Outbox, distributed locks, heartbeats, bounded retry, and dead-letter/manual-review paths. |
| `BAK-001` | Configurable 10-minute database, daily full, and pre-update backups are consistent, encrypted, checksummed, retained locally, and optionally sent to Telegram in <=45 MB parts with manifest. |
| `BAK-002` | Restore verifies manifest, checksums, encryption key, software/schema compatibility, maintenance mode, dry-run/preflight, database and private files, and post-restore smoke tests. |
| `INS-001` | Browser installer checks both PHP runtimes, extensions, permissions, DB, Redis, HTTPS, Telegram, channels, SMS, keys, migrations, seed, webhook, Owner, and permanently locks itself. |
| `UPD-001` | Updater verifies signature/checksum/compatibility, checks active financial work, creates backup, stages release, runs migrations and smoke tests, atomically activates, and can roll back safely. |
| `SEC-001` | Secrets are never hard-coded or logged; TLS verification is mandatory; callbacks/webhooks are authenticated; SSRF, file upload, replay, CSRF, authorization, injection, and rate-limit controls are tested. |
| `QUA-001` | Every requirement maps to code, automated tests, commands, results, and evidence; no phase closes with a failed financial, authorization, provisioning-idempotency, backup-restore, or Critical/High security gate. |

---

# 37. Definition of Done

The project is done only when all conditions are met:

1. Every requirement in this specification is implemented and verified.
2. The delivered repository is fully defined by this specification and its checked-in documentation.
3. Fresh installation succeeds on a target-like aaPanel/OpenLiteSpeed environment.
4. Telegram onboarding, purchase, payment, provisioning, service management, support, and admin flows work.
5. Manual and automatic card-to-card verification work end to end with runnable Fake and Generic REST providers.
6. Manual and automatic gift-card verification work end to end with runnable Fake and Generic REST providers.
7. A real provider-specific card/gift adapter can be added without changing Order or Provisioning modules.
8. Payment callbacks, bank transactions, gift-card redemption, wallet actions, and provisioning are idempotent.
9. No paid service is created before authoritative payment capture.
10. No duplicate service is created under retry, timeout, worker crash, or duplicate message conditions.
11. Ledger is balanced and reconciliation passes.
12. Roles, multiple-role assignment, per-admin allow/deny overrides, and sensitive permissions work.
13. All customer-visible text is centralized and Persian default content is complete.
14. Colored Telegram buttons and premium emoji degrade safely.
15. Ticket and broadcast full lifecycles work, including edit/pin/unpin/delete where Telegram permits.
16. One Cron entry drives Scheduler; workers are supervised.
17. Logs, alerts, correlation IDs, audit, and Operations Center are functional without secret leakage.
18. Encrypted backup is generated and a full restore rehearsal passes.
19. Updater and rollback rehearsal pass.
20. Security review has no unresolved Critical or High finding.
21. Mandatory tests and static gates pass.
22. No known Critical or High product defect remains.
23. Final source, package, documentation, and evidence are delivered.
24. Production deployment and post-deployment checks are documented and executable by the owner.

---

# 38. Final Command

Execute this project as a complete engineering program, not as a code-generation demo. Build in phases, maintain traceability, test every financial and authorization boundary, use authoritative provider verification, preserve manual fallbacks, and deliver a secure, installable, updateable, recoverable, auditable, production-ready `1.0.0` release.

Everything necessary to plan and build the defined product is contained in this specification. Request only real credentials, server actions, and genuinely unspecified high-impact business decisions at the moment they are required.
