# Execution Ledger

Document version: `0.1.0`  
Source baseline: Master Execution Prompt `1.0.0` dated `2026-08-03`  
Ledger state: `active`  
Implementation state: `in-progress`

## Purpose and rules

This is the program control ledger. It records phase scope, ownership, decisions, dependencies, required evidence, and closure gates. `in-progress` means a reviewed partial implementation exists; it never means the phase gate has passed. Documentation-only entries do not constitute runtime test evidence.

## Delivery ledger

| Phase | Scope | Accountable owner | Required independent review | Status | Gate evidence (future path) |
|---|---|---|---|---|---|
| `0.1.0` | Requirements, journeys, architecture, ERD, state machines, permissions, risks, threat model, test strategy, ADRs, traceability | Lead / Product / Architect | Security, Finance, QA | `in-review` | `evidence/0.1.0/quality-gate.md` |
| `0.2.0` | Laravel foundation, module skeleton, CI, installer skeleton, health, Outbox, idempotency, base schema, deployment layout | Architect / Platform | SRE, Security, QA | `in-progress` | `evidence/0.2.0/quality-gate.md` |
| `0.3.0` | Identity, customers, agents, ACL, OTP/SMS, identity items, audit | Identity & Access | Security, QA | `planned` | `evidence/0.3.0/quality-gate.md` |
| `0.4.0` | Catalog, offerings, servers, panels, capacity, custom plans, trials | Catalog / Provisioning | Architect, QA | `planned` | `evidence/0.4.0/quality-gate.md` |
| `0.5.0` | Ledger, wallet, pricing, promotions, all payment providers and reconciliation | Finance / Payments | Independent Security, QA | `planned` | `evidence/0.5.0/quality-gate.md` |
| `0.6.0` | Orders, provisioning, service lifecycle, delivery, import, synchronization, notifications | Orders / Provisioning | Finance, Security, QA | `planned` | `evidence/0.6.0/quality-gate.md` |
| `0.7.0` | Persian Telegram UX, content, membership, support, direct messaging, broadcast | Telegram / Support | Product, Security, QA | `planned` | `evidence/0.7.0/quality-gate.md` |
| `0.8.0` | Reports, Operations Center, alerts, backup/restore, updater/rollback, worker deployment | SRE / Operations | Security, Finance, QA | `planned` | `evidence/0.8.0/quality-gate.md` |
| `0.9.0` | Full regression, hardening, security review, load/chaos baseline, release rehearsal | QA / Security / Release | Lead | `planned` | `evidence/0.9.0/release-candidate-gate.md` |
| `1.0.0` | Signed/checksummed package, final reports, runbooks, handover, deployment checklist | Lead / Release | Owner acceptance | `planned` | `evidence/1.0.0/release-gate.md` |

## Mandatory workstreams

| Workstream | Primary owner | Review owner | Initial phase | Status |
|---|---|---|---|---|
| Product scope and Persian terminology | Product Analyst | Lead | `0.1.0` | `planned` |
| Modular architecture and contracts | Solution Architect | Security | `0.1.0` | `planned` |
| Schema and financial integrity | Financial Integrity Engineer | QA | `0.1.0` | `planned` |
| Telegram transport and UX | Telegram Engineer | Product | `0.2.0` | `planned` |
| Payment integrations | Payment Engineer | Finance/Security | `0.5.0` | `planned` |
| Panel adapters and provisioning | Provisioning Engineer | Architect/QA | `0.4.0` | `planned` |
| Identity, ACL, and support | Identity & Access Engineer | Security | `0.3.0` | `planned` |
| Deployment, backup, update, observability | SRE/Release Engineer | Security/QA | `0.2.0` | `planned` |
| Automated quality evidence | QA Engineer | Lead | `0.2.0` | `planned` |
| Independent security sign-off | Security Reviewer | Lead | `0.9.0` | `planned` |

## Baseline decisions

| Decision | Baseline | Authority | Status |
|---|---|---|---|
| Architecture | Laravel `13.x` modular monolith; Application Services are presentation-independent | Master Prompt §4 | `planned` |
| Runtime | Ubuntu 22.04, PHP 8.4 CLI and LSPHP, MariaDB, authenticated Redis | Master Prompt §2 | `planned` |
| User interface | Telegram-first; Persian visible default; English fallback; future web UI reuses services | Master Prompt §§1, 17, 18 | `planned` |
| Money | Integer IRR at rest; explicit tested Toman display; fixed-precision crypto | Master Prompt §5.1 | `planned` |
| Payments | Exactly one captured settlement per order; wallet is a complete method; provider return is never proof | Master Prompt §§1.2, 9, 10 | `planned` |
| Financial history | Immutable balanced ledger; compensating entries only | Master Prompt §14 | `planned` |
| External effects | Transactional Outbox, idempotency keys, database uniqueness, bounded retries | Master Prompt §§4.2, 9.6, 24 | `planned` |
| Deployment | Atomic release directories with `current` symlink; only `public/` web-accessible | Master Prompt §2.4 | `planned` |
| Scheduling | Exactly one Laravel Scheduler Cron; workers supervised separately | Master Prompt §2.5 | `planned` |
| Default verification | Card and gift card use `automatic_then_manual`; fake and Generic REST implementations required | Master Prompt §§11.1, 12.2 | `planned` |

## Deferred owner decisions (not current blockers)

These decisions must be resolved before the named boundary; code must expose validated configuration rather than silently choosing a financially material policy.

| Decision ID | Decision needed | Recommended default / safe interim | Required by | Status |
|---|---|---|---|---|
| `DEC-BIZ-001` | High-value thresholds and which actions require dual approval | Require Owner approval for large financial corrections/refunds/batches; exact amount unset | Before `0.5.0` acceptance | `planned` |
| `DEC-BIZ-002` | NOWPayments under/over/partial payment and expiry disposition | Never auto-fulfil mismatches; route to manual review | Before live provider activation | `planned` |
| `DEC-BIZ-003` | Card/gift/USDT external refund destination policy | Manual review; no automatic refund until destination policy is approved | Before `0.5.0` acceptance | `planned` |
| `DEC-OPS-001` | Expected volume and performance targets | Run a documented conservative baseline; no final capacity certification | Before `0.9.0` | `planned` |
| `DEC-OPS-002` | Backup private-media inclusion, remote destination, and retention overrides | Encrypted DB/config/manifest; defaults 7 daily, 4 weekly, 6 monthly | Before production backup activation | `planned` |
| `DEC-INT-001` | Actual bank-transaction verification provider contract | Build Fake + Generic REST; keep real adapter disabled | Before real automatic verification | `planned` |
| `DEC-INT-002` | Actual gift-card provider contract/types and partial-capture policy | Build Fake + Generic REST; partial capture disabled | Before real automatic verification | `planned` |
| `DEC-INT-003` | Installed Marzban/PasarGuard versions and credentials | Contract fakes first; activate only after target-version contract test | Before production panel activation | `planned` |

## Owner inputs by just-in-time boundary

No secret belongs in chat, source control, fixtures, logs, screenshots, or evidence. Secrets shall be entered through the installer, a hidden interactive command, or a protected server-side secret file.

| Boundary | Non-secret input / secure secret input | Status |
|---|---|---|
| Staging integration | Telegram bot and Owner/report IDs; test DB/Redis; panel/provider sandbox credentials | `not-started` |
| Payment acceptance | Destination bank metadata, gift-card types, USDT BEP20 address, Zarinpal/NOWPayments credentials | `not-started` |
| SMS acceptance | Melli Payamak and Kavenegar credentials/templates | `not-started` |
| Release acceptance | Backup key setup, private backup target, production endpoints, final load assumptions | `not-started` |

## Gate policy

- Stop the release when a financial invariant, authorization boundary, provisioning idempotency, restore rehearsal, or Critical/High security gate fails.
- No author alone approves financial, authorization, installer, updater, backup, or provider-integration changes.
- Every merge references requirement IDs and automated tests.
- Every test claim records exact command, environment, result, and evidence path.
- Phase closure is prohibited while placeholders remain for required code, tests, commands, results, or evidence.

## Current ledger note

The reviewed planning baseline and a partial `0.2.0` Laravel foundation now exist. PHP runtime gates remain unverified until GitHub Actions produces retained evidence. No provider was contacted and no deployment was performed.
