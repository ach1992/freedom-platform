# Phase 0.4 PasarGuard Guarded Live-Acceptance Harness Traceability

Status: **evidence-complete; actual live provider execution pending protected dispatch.**

This document maps the guarded PasarGuard live-acceptance harness to Phase `0.4.0` requirements and the deferred live matrix. It does not mark live provider rows complete.

## Accepted implementation boundary

- SHA: `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- CI: `31226863010` / run `#1013` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31226863010`;
- artifact ID: `9012421937`;
- independently verified SHA-256: `72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`;
- evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`.

## Accepted evidence boundary

Evidence content/traceability head:

- SHA: `71ca4b39df41bc9fcf725c30e9caba3285ee5412`;
- mandatory CI: `31227084007` / run `#1015` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31227084007`;
- artifact ID: `9012490991`;
- uploader SHA-256: `0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- independently verified SHA-256: `0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`.

The extracted evidence artifact contained exactly five expected files and an independent credential/value scan found no PasarGuard API-key value, authorization credential or live subscription URL.

## Requirement mapping

| Requirement | Harness contribution | Evidence state |
|---|---|---|
| `PRV-001` | Exact PasarGuard `v5.2.1` gate before mutation; API-base, inbound and group discovery | harness evidence-complete; live deployment proof pending |
| `PRV-002` | Authoritative absence-before-create, one create, post-read equality, mismatch/no-overwrite, expiry/data/reset/status/rotate/delete sequence | deterministic harness/test proof accepted; real effect pending |
| `PRV-003` | Delivery artifact redaction, in-memory subscription rotation comparison and sanitized artifact output | deterministic harness/test proof accepted; real delivery pending |
| `SEC-001` | Protected Actions Secrets, no ordinary credential input, HTTPS-only transport, no redirects | implementation/CI proof accepted; deployed TLS/auth proof pending |
| `SEC-002` | Fail-closed exact version, lookup-before-create, cleanup discovery and sanitized errors | implementation/CI proof accepted |
| `DAT-001` | Deterministic disposable identity, authoritative read-before-effect and final absence verification | implementation/CI proof accepted; real provider proof pending |
| `DAT-003` | Data-limit Set/Add uses accepted mapper and authoritative finite snapshot for Add | implementation/CI proof accepted; real provider proof pending |
| `QUA-001` | Unit transport coverage plus complete mandatory CI and retained artifacts | evidence-complete harness proof |

## Live matrix mapping

The harness is prepared to execute these rows when protected credentials are configured and the manual workflow is authorized:

- `LIVE-001` protected authentication/API-key path;
- `LIVE-002` exact version/health gate;
- `LIVE-003` compatible inbound/target discovery input;
- `LIVE-004` enabled group/capability input;
- `LIVE-005` authoritative username absence;
- `LIVE-006` create plus provider-specific equivalence;
- `LIVE-007` post-create authoritative lookup/numeric identity;
- `LIVE-008` mismatch detection without overwrite;
- `LIVE-011` expiry mutation;
- `LIVE-012` absolute data-limit mutation;
- `LIVE-013` additive data-limit mutation from authoritative state;
- `LIVE-014` usage reset;
- `LIVE-015` suspend;
- `LIVE-016` activate;
- `LIVE-017` delivery artifact redaction/validation;
- `LIVE-018` subscription rotation;
- `LIVE-022` delete;
- `LIVE-023` final authoritative absence/cleanup.

The following remain intentionally deferred because their evidence requires the application coordinator, controlled network/provider fault injection or explicit activation acceptance:

- `LIVE-009` exact-match adoption through the application integration path;
- `LIVE-010` idempotency-key conflict/replay against the real integration path;
- `LIVE-019` transport timeout uncertainty;
- `LIVE-020` provider 5xx uncertainty;
- `LIVE-021` rate-limit/retry/discovery behavior;
- `LIVE-024` explicit Service Target activation acceptance.

No deferred row is implicitly satisfied by the existence of the harness.

## Safety trace

The accepted harness preserves the previously verified invariants:

- authoritative lookup before create;
- unavailable/ambiguous lookup means no create;
- exact create equivalence remains provider-specific;
- mismatch means no overwrite;
- provider mutation result mapping remains fail-closed;
- cleanup performs discovery before delete;
- TLS verification remains enabled;
- raw credentials/provider response/subscription material are not retained in normal evidence;
- runtime PasarGuard mutation/delivery capabilities remain unadvertised;
- real Service Targets remain disabled/unverified.

## Provider sequencing decision

Owner decision on 2026-08-08:

- PasarGuard is the active provider for current controlled live acceptance;
- Marzban live acceptance is deferred to the final project/release acceptance stage;
- existing Marzban `v0.8.4` pinned source/offline evidence remains valid but is not upgraded to a live compatibility claim;
- Phase `0.4.0` / Issue `#7` therefore remains open until the carried Marzban live requirement and final closure audit are accepted.

## Remaining execution gate

The harness increment is evidence-complete. Actual PasarGuard live rows remain pending until:

1. protected Actions Secrets are configured outside repository text;
2. `.github/workflows/provider-live-acceptance.yml` is manually dispatched on `develop/v1.0.0-completion` with its exact confirmation value;
3. the resulting sanitized provider-live artifact is reviewed;
4. any failed/uncertain mutation is reconciled before a retry;
5. the separately deferred fault/idempotency/Target activation rows are executed where applicable.

The existence of user-supplied credentials outside the protected Actions secret store is not treated as accepted live evidence and those values are intentionally not recorded here.
