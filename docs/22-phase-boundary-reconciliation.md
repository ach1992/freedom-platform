# Phase Boundary and Task Reconciliation

Date: 2026-08-05
Authoritative contract: `docs/specification/master-execution-prompt.md`
Branch: `develop/v1.0.0-completion`
Draft PR: #6

## Purpose

This record reconciles GitHub Tasks and repository planning text against actual code, tests, CI and retained evidence. It does not mark implementation complete by itself.

## Closure rule

A Task or phase may be marked complete only when all three conditions exist for the same accepted boundary:

1. production implementation is present on the development branch;
2. the exact implementation/evidence SHA passes the mandatory standard CI and required target/contract rehearsals;
3. requirement traceability and retained evidence identify the code, tests, commands/results and artifacts.

Schema presence, a planning checkbox, an Issue comment or an unverified `[skip ci]` commit is never sufficient.

## Closed Task audit

### Issue #4 / Phase 0.2

Closure remains valid. The repository contains actual installer/runtime/Telegram-ingress code and automated tests, including resumable installer journal/lock, release activation/rollback, worker/Scheduler heartbeats and authenticated encrypted replay-safe webhook ingestion. Retained closure evidence is `evidence/0.2.0/PHASE-CLOSURE.md`.

The old Ledger wording that split installer work into Phase 0.1 and renamed Phase 0.2 as a Telegram-only phase was not authoritative and is corrected in `docs/00-execution-ledger.md`.

### Issue #5 / Phase 0.3

Closure remains valid. Identity, customer, agent and access-control services, migrations and unit/feature tests exist. The final closure trigger `979b0c99d79dbcc8cff273ccdfad2ea612796a0c` passed CI `31035712555` with 194 tests and 958 assertions; evidence is `evidence/0.3.0/phase-closure-verification.md`.

Planning status phrases that still say `in-progress` or `not-started` for these accepted Phase 0.3 boundaries are stale status text, not authority to reopen the completed phase. They must be synchronized when those large planning catalogues are next rewritten; this reconciliation and the closure evidence govern current status.

## Duplicate and scope audit

Issue #14 duplicated the active Phase 0.4 tracker and incorrectly moved pricing/payment/order work into Phase 0.4. It was closed as `duplicate`, not `completed`.

Issue #7 is the authoritative Phase 0.4 tracker:

- Catalog, Plan Offerings, Panels/Targets, capabilities/capacity, custom plans, trials, fallback and panel adapters.

Issue #8 owns Phase 0.5:

- ledger, pricing/discount/referral/agent pricing, immutable quotes, Payment Intents/providers and payment-proof lifecycle.

Issue #9 owns Phase 0.6:

- Order/Order Item state, provisioning orchestration, remote idempotency and service lifecycle.

Issues #10–#13 retain the authoritative Phase 0.7–1.0 boundaries.

## Current Candidate

The current pre-increment branch head `bc425ff9d378a06837a4f229cd48e1cb19a029bb` is Unverified. Its initial product catalog migration is being replaced with a Master-Prompt-aligned Category/Product/Variant lifecycle candidate. No Phase 0.4 Task will be checked or closed until code and exact-SHA CI/evidence pass.
