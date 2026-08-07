# Project Status

This is the single human-readable current-state entry point. It is intentionally concise. Detailed history belongs in evidence, traceability, and handoff documents.

**Last status review:** 2026-08-07  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative phase Issue:** `#7`  
**Authoritative integration PR:** `#6`  
**Allowed branch:** `develop/v1.0.0-completion`  
**PR base/state:** `main` / Draft

## Live-state rule

Do not treat a SHA written in this document as current. Before work, fetch PR `#6` and use its exact `head_sha`. Then fetch workflow runs for that SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last independently verified boundary

### Phase 0.4 increment 5 — Custom Plan Policy and Calculation Snapshot

Implementation boundary:

- SHA: `8e62867277acdd39cd1471ed3d454ef25520bef8`
- CI: `31071843621` / run `#833` — success
- suite: 255 tests, 1267 assertions

Evidence boundary:

- SHA: `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`
- CI: `31102652203` / run `#834` — success
- artifact: `test-evidence-31102652203`
- artifact ID: `8968201643`
- digest: `sha256:5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9`
- evidence: `evidence/0.4.0/custom-plan-policy-calculation.md`
- traceability: `docs/29-phase-0.4-custom-plan-traceability.md`

No later implementation is independently accepted yet.

## Active unverified bounded increment

### Trial Policy and Panel Adapter Foundation

Authoritative handoff: `docs/30-phase-0.4-trial-panel-handoff.md`

Current bounded scope:

- trial policy, eligibility, capacity reservation, abuse controls, regrant/reset authority, and disclosed fallback;
- common Panel Adapter contract and capability/result/snapshot validation;
- `FakePanelAdapter` deterministic remote behavior and mutation idempotency journal;
- authoritative deterministic-username lookup;
- exact-match adoption, mismatch conflict/manual review, and unavailable-lookup fail-closed behavior;
- uncertain-create discovery before any subsequent create;
- credential and delivery-artifact redaction;
- unavailable Marzban/PasarGuard shells that fail closed.

Explicitly unverified:

- mandatory CI on the exact current implementation head;
- final Trial/Panel Adapter test and assertion counts;
- retained implementation artifact and digest;
- bounded evidence and traceability head;
- real Marzban or PasarGuard version/API compatibility;
- live provider or production activation.

## Immediate stabilization gate

Feature development is temporarily paused at this bounded increment until repository control is restored.

Required order:

1. make the self-hosted CI runtime deterministic and green on the exact PR head;
2. apply the currently identified lockfile security update and focused formatting repair without weakening checks;
3. remove temporary repair automation;
4. finish the project-control documentation and machine checks;
5. reconcile stale README, ledger, traceability, risk, PR, and Issue status;
6. archive or disable obsolete/destructive staging workflows and document the safe staging workflow set;
7. run mandatory CI on the exact stabilization head;
8. only then resume Trial/Panel implementation verification.

## Known current blockers and risks

- The self-hosted runner previously hung in `setup-php` because it required interactive `sudo`; CI now uses the preinstalled PHP toolchain instead.
- The runner toolchain must select `/www/server/php/84/etc/php-cli.ini`, disable JIT for CI, enable PCOV only for coverage, and validate PHP/Composer/extensions before expensive work.
- The previously locked `league/commonmark` `2.8.3` is affected by `CVE-2026-3066`; the bounded repair targets `2.9.0` or later while preserving the lockfile.
- Five active-increment files require Pint-only formatting repair.
- Several core status documents and the PR body describe old phases or old active work.
- Multiple one-time/destructive staging workflows remain visible and can be run manually; they need an explicit safe-status inventory and cleanup.
- The long-running PR is intentionally retained, but its size makes machine-readable status and strict continuation rules mandatory.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- exact match means adopt, not create;
- mismatch means conflict/manual review;
- unavailable lookup means no create;
- uncertain create means discovery first, never immediate second create;
- conflicting idempotency-key reuse never overwrites the original effect;
- TLS verification is never disabled;
- system CA is default; custom CA or pinning is limited to explicitly configured private/self-signed endpoints.

## Next completion boundary

The next acceptable boundary is not “code exists.” It is:

- exact implementation SHA;
- all mandatory jobs green on that SHA;
- executable test/assertion counts;
- retained artifact name/ID and independently calculated SHA-256;
- bounded Trial/Panel evidence and traceability;
- all mandatory jobs green on the exact evidence-head SHA;
- only then updates to Issue `#7` and PR `#6`.
