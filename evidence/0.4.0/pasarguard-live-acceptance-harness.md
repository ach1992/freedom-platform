# Phase 0.4 PasarGuard Guarded Live-Acceptance Harness

Status: **Implementation Green; live provider execution not yet performed.**

This evidence records the code, controls and exact-head CI for the guarded PasarGuard live-acceptance harness. It does **not** claim that any live PasarGuard request or mutation has been executed successfully.

## Scope

This bounded increment adds a manual, secret-backed acceptance harness for the already pinned PasarGuard `v5.2.1` source contract.

Implemented:

- `scripts/ci/PasarGuardLiveAcceptance.php` — reusable fail-closed live sequence;
- `scripts/ci/pasarguard-live-acceptance.php` — HTTPS/cURL entrypoint with safe output;
- `tests/Unit/Deployment/PasarGuardLiveAcceptanceTest.php` — deterministic transport tests;
- `.github/workflows/provider-live-acceptance.yml` — manually dispatched, self-hosted guarded workflow.

No real provider runtime gateway mutation capability or Service Target activation is enabled by this increment.

## Guarded execution contract

Execution requires all of the following:

- branch `develop/v1.0.0-completion`;
- manual `workflow_dispatch`;
- exact confirmation value owned by the workflow/harness;
- protected `PASARGUARD_TEST_ORIGIN` and `PASARGUARD_TEST_API_KEY` GitHub Actions Secrets;
- HTTPS-only transport with peer/host TLS verification and redirects disabled;
- exact PasarGuard version `5.2.1` before any mutation;
- authoritative unique-username absence proof before create.

The workflow does not accept provider credentials as ordinary workflow inputs and repository evidence contains no provider credential value.

## Disposable live sequence implemented

When the protected workflow is eventually executed, the harness can verify:

1. authentication/API base discovery;
2. exact `v5.2.1` version;
3. inbound and enabled-group discovery;
4. authoritative unique-username absence;
5. one create and provider-specific post-read create equivalence;
6. numeric-ID read identity;
7. mismatch detection without overwrite;
8. expiry update;
9. absolute data-limit update;
10. additive data update from authoritative finite state;
11. usage reset;
12. suspend and activate;
13. delivery redaction and subscription rotation proof using in-memory hashes only;
14. delete plus final authoritative absence;
15. best-effort fail-closed cleanup in `finally`, with discovery before delete.

The output contains only sanitized facts, including a SHA-256 of the disposable username; it never emits the API key, password, raw subscription URL or raw provider body.

## Deliberately deferred live rows

The harness does not manufacture proof for rows that require controlled fault injection or higher-level runtime activation. These remain separate acceptance work:

- duplicate exact-match adoption under the application coordinator;
- idempotency conflict replay against the real integration path;
- controlled timeout/5xx/rate-limit uncertainty and post-effect discovery;
- explicit production/runtime Service Target activation.

Those rows remain fail-closed/unaccepted until separately executed and evidenced.

## Marzban decision

By owner decision on 2026-08-08, Marzban live acceptance is deferred until the final project/release acceptance stage. This increment neither weakens nor closes the existing pinned Marzban `v0.8.4` source/offline evidence and makes no live Marzban claim.

## Implementation boundary

Accepted implementation candidate:

- SHA: `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- mandatory CI: `31226863010` / run `#1013` — success;
- suite: **317 tests, 1730 assertions**;
- artifact: `test-evidence-31226863010`;
- artifact ID: `9012421937`;
- uploader SHA-256: `72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`;
- independently verified SHA-256: `72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`.

Mandatory jobs passed on the exact candidate:

- repository preflight/project-control verification;
- Git history secret scan;
- Pint;
- PHPStan/Larastan and repository policy checks;
- dependency audit/license policy;
- complete MariaDB/password-authenticated Redis suite.

The retained test artifact contains exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

An independent credential/value scan of the extracted artifact found no PasarGuard API-key value, protected-secret variable value, authorization credential or live subscription URL.

## Corrective CI cycle

The first harness candidate was rejected by CI because of Pint formatting and an invalid unit-test repository-path helper. Those findings were corrected before this accepted boundary. No live provider mutation was attempted during the corrective cycle.

## What is proven

This increment proves that:

- the PasarGuard live sequence is encoded behind explicit manual and secret-backed gates;
- version mismatch stops before mutation;
- deterministic tests exercise one-create/one-delete cleanup and the accepted mutation mapper sequence;
- sanitized harness output does not contain the tested API key, subscription URL or run identifier;
- implementation passes the full mandatory repository quality gates.

## What is not proven

This evidence does **not** prove:

- successful authentication to the owner-provided PasarGuard deployment;
- deployment-specific PasarGuard version or reverse-proxy/API-base behavior;
- any real remote create/update/reset/suspend/activate/rotate/delete effect;
- real post-effect uncertainty reconciliation;
- provider-side idempotency;
- production Service Target activation;
- live Marzban compatibility;
- Phase `0.4.0` closure.

Actual live acceptance must be recorded only from the guarded workflow's sanitized artifact after protected secrets are configured and the workflow is manually authorized.
