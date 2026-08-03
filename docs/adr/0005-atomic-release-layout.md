# ADR 0005: Immutable Atomic Release Layout

- **Status:** Accepted
- **Date:** 2026-08-02

## Context

Deployment targets one aaPanel/OpenLiteSpeed host and must support verified updates, rollback, shared durable state, and minimal partial-deployment exposure. Code and mutable runtime data have different lifecycles.

## Decision

Extract each verified package into an immutable `releases/<version>` directory. Keep `.env`, storage, backups, update/restore work, provider certificates, and installer lock under `shared/`. Link required shared paths into the staged release, run prerequisites/migrations/smoke checks, then atomically switch `current`. OpenLiteSpeed serves only `current/public`. Workers are drained/restarted against the new release. CLI and LSPHP runtimes are preflighted independently.

Rollback switches the symlink only when release/schema compatibility metadata permits it. Database changes use expand/contract migrations; otherwise rollback requires the documented pre-update restore path and accepted data-loss window.

## Consequences

- Code activation is atomic and the prior release remains available.
- Shared writable directories require strict ownership, permissions, backup, and compatibility discipline.
- Symlink rollback cannot undo incompatible database migration, external provider effects, or new writes; updater must block unsafe rollback.
- Release packages need signed/checksummed manifests and must contain no secrets.
- OpenLiteSpeed document root and PHP runtime differences become explicit installer checks.

## Rejected alternatives

- In-place overwrite: leaves mixed versions on interruption and weak rollback.
- Exposing project root: risks disclosure of configuration/source/storage.
- Container-only deployment: not the specified aaPanel/OpenLiteSpeed operating model; may be reconsidered for non-production development.
