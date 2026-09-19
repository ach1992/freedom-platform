## Authority / owning Issue

Authority: requirement / Phase / Program / Issue

Closes/Refs # (when applicable)

Use `Closes` only when this PR completes the **entire** owning Task Contract. Use `Refs` when this PR is a partial implementation step and keep the Task Issue open.

## Summary

What changed and what observable outcome this PR establishes or preserves.

Keep substantive work Draft while implementation/self-review corrections are still expected. Ready means the current candidate is intended to consume acceptance CI; if material correction resumes, convert back to Draft before pushing further correction commits.

State an adjacent nonclaim only when the scope could otherwise be misunderstood.

## Risk / material impact

- Change risk: Low / Medium / High / Critical
- Material security/data/financial/provider/schema/config/runtime/release impact (only when applicable):

High/Critical financial, authorization, security, provider, schema, deployment/release, secret, or irreversible work requires independent review and explicit Owner approval unless the exact action was already authorized.

When independent review is required, follow the Owner-relayed fresh-ChatGPT workflow in `CONTRIBUTING.md`; do not request GitHub/Copilot/external reviewers.

## Verification

Expected validation profile/checks: CONTROL / CONTROL_PLANE / APPLICATION / OPERATIONS / FULL; list only material job domains when useful

Focused commands/tests/results or applicable CI reference:

```text
...
```

If prior green evidence is being reused, state only why the tested resulting tree remains materially unchanged. Do not copy CI logs into the PR.

## Review gates

- [ ] The diff remains inside the declared authority, scope, and acceptance boundary.
- [ ] The applicable validation passes on the final candidate, or a valid unchanged result is explicitly reused.
- [ ] Required independent/Owner review is complete when risk requires it.
- [ ] No per-task handoff/status/traceability/evidence document was added.

Preferred merge method: squash / merge / rebase
