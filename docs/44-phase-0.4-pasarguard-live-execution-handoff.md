# Phase 0.4 PasarGuard Live-Execution Handoff

**Status:** PasarGuard harness evidence-complete; live execution blocked only on protected GitHub Actions secret configuration/manual dispatch.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Branch:** `develop/v1.0.0-completion` only.

## Last accepted boundary

### PasarGuard Guarded Live-Acceptance Harness

Implementation:

- SHA: `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- CI: `31226863010` / run `#1013` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31226863010`, ID `9012421937`;
- independently verified digest: `sha256:72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`.

Evidence:

- SHA: `71ca4b39df41bc9fcf725c30e9caba3285ee5412`;
- CI: `31227084007` / run `#1015` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31227084007`, ID `9012490991`;
- independently verified digest: `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability: `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

## Current provider schedule

Owner decision on 2026-08-08:

- PasarGuard `v5.2.1` is the provider to test now;
- Marzban `v0.8.4` live acceptance is deferred until the final project/release acceptance stage;
- the Marzban requirement is **not removed** and Phase `0.4.0` / Issue `#7` is **not closed** by this scheduling decision.

The generic matrix remains `docs/41-phase-0.4-provider-live-acceptance-matrix.md`. For current execution, apply its PasarGuard rows only. Marzban rows remain deferred release blockers.

## Protected PasarGuard execution

The repository now contains:

- read-only probe workflow: `.github/workflows/provider-readiness.yml`;
- guarded mutation workflow: `.github/workflows/provider-live-acceptance.yml`;
- reusable live harness: `scripts/ci/PasarGuardLiveAcceptance.php`;
- safe entrypoint: `scripts/ci/pasarguard-live-acceptance.php`.

Required repository Actions Secrets:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

Do not place their values in commits, workflow inputs, Issues, PR comments, evidence or logs.

After the Secrets exist, manually dispatch `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation string. The optional group ID should remain empty unless a specific enabled disposable-test group has been approved.

The assistant's available GitHub connector can inspect runs/artifacts and re-run failed jobs, but it cannot create/update Actions Secrets or initiate a new `workflow_dispatch`. Therefore secret insertion/manual dispatch is the only current human action required for the actual PasarGuard live run.

## What the guarded run may prove

Prepared live rows:

- authentication/version/base-path discovery;
- compatible inbound/group discovery;
- authoritative unique-username absence;
- one create and provider-specific post-read equivalence;
- mismatch detection without overwrite;
- expiry/data Set/data Add/reset/suspend/activate;
- protected delivery/rotation proof without emitting the URL;
- delete and final authoritative absence/cleanup.

Still separate after that run:

- coordinator-level exact-match adoption;
- real idempotency conflict/replay;
- controlled timeout/5xx/429 fault injection and discovery-before-retry;
- explicit Service Target activation acceptance.

A failed or uncertain effectful row stops later effectful rows until authoritative discovery/reconciliation completes.

## Safety state

Until accepted live evidence exists:

- real PasarGuard runtime mutation/delivery capabilities remain unadvertised/fail-closed;
- real Service Targets remain disabled/unverified;
- source/offline evidence must not be described as deployment compatibility;
- no production customer identifier or sensitive provider response is used for acceptance;
- no live Marzban claim is permitted;
- Phase `0.4.0` remains open.

## Parallel project continuation

The master specification forbids closing a phase before its Quality Gate; it does not authorize silently dropping a requirement. Because the owner explicitly deferred only the timing of Marzban live acceptance, later-phase implementation may continue as a carry-forward schedule exception **without** marking Phase `0.4.0` complete, weakening any Phase 0.4 invariant, or activating unverified provider Targets.

Any release/production readiness claim must still resolve the carried Marzban gate and the remaining PasarGuard live/fault/activation rows before final `1.0.0` acceptance.
