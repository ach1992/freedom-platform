## Owning Issue

Closes/Refs #

## Bounded summary

What changed and what accepted behavior this PR is intended to establish or preserve.

## Explicit nonclaims

List adjacent features, effects, environments, provider behavior, or release claims this PR does **not** establish.

## Changed surfaces

- Modules / paths:
- Database / migrations:
- Configuration / environment:
- Runtime / deployment / operations:
- External/provider effects:

## Risk and safety

- Risk level: Low / Medium / High / Critical
- Security / authorization impact:
- Sensitive-data impact:
- Financial-integrity impact:
- Remote/provider-effect impact:

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval.

## Verification

- [ ] Focused success/validation tests are present where applicable.
- [ ] Authorization and fail-closed tests are present where applicable.
- [ ] Replay/conflict/idempotency tests are present where applicable.
- [ ] MariaDB concurrency/locking tests are present where correctness depends on them.
- [ ] Failure/uncertainty and regression behavior is covered where applicable.
- [ ] Migration/config/runtime compatibility is verified where applicable.
- [ ] Mandatory CI passes on the exact final PR head.

Commands / focused tests run:

```text
...
```

Exact-head CI run:

```text
pending until final head
```

## Review and integration

- [ ] Diff remains inside the owning Issue scope.
- [ ] No per-task handoff/traceability/evidence document was added.
- [ ] No unresolved review thread remains.
- [ ] Required CODEOWNERS/reviewer input is complete where configured.
- [ ] Explicit Owner approval is recorded when required by risk.
- [ ] Merge method matches `CONTRIBUTING.md`; merge style does not substitute for review or CI.

Preferred merge method: squash / merge / rebase
