# Staging Workflow Inventory

**Reviewed:** 2026-08-07  
**Current policy:** no historical `staging-*` workflow is approved to run merely because it exists. Active staging automation must be explicitly classified, guarded, and linked from `PROJECT_STATUS.md`.

## Classification

| Workflow | Historical purpose | Effect class | Audit decision |
|---|---|---|---|
| `staging-inventory.yml` | remote OS/service/tool/DNS inventory | read-only remote SSH | replace with one self-hosted, secret-free readiness workflow |
| `staging-stack-inventory.yml` | inspect aaPanel installer paths and service state | read-only remote SSH | replace with one self-hosted, secret-free readiness workflow |
| `staging-provision-diagnose.yml` | inspect aaPanel installation logs and paths | read-only but exposes broad host metadata | remove from active Actions; use an incident-specific bounded diagnostic when needed |
| `staging-provision-base.yml` | install packages and aaPanel foundation as root | destructive/root mutation | remove from active Actions; historical evidence only |
| `staging-provision-recover.yml` | retry aaPanel/root service provisioning | destructive/root mutation | remove from active Actions; historical evidence only |
| `staging-install-stack.yml` | install OpenLiteSpeed/PHP/runtime stack | destructive/root mutation | remove from active Actions; historical evidence only |
| `staging-deploy-core.yml` | deploy an earlier verified application SHA | destructive deployment with stale hard-coded SHA | remove from active Actions |
| `staging-site-http.yml` | configure aaPanel/OpenLiteSpeed site and test plain HTTP | destructive server configuration | remove from active Actions |
| `staging-runtime-gate-once.yml` | one-time runtime/release/worker gate | mixed diagnostic and target mutation | remove from active Actions; accepted evidence remains in `evidence/0.2.0/` |
| `staging-verify-telegram-webhook.yml` | configure and verify the real Telegram webhook | real external mutation using protected secrets | remove until the Telegram integration phase has a current contract and owner-approved gate |

## Why removal is safe

- Git history preserves every workflow and script.
- Accepted Phase `0.2.0` staging evidence remains under `evidence/0.2.0/` and in retained Actions artifacts.
- Removing a workflow file prevents accidental dispatch; it does not erase evidence or implementation.
- Future staging operations must be rebuilt from the current runtime and release contract, not copied blindly from one-time recovery automation.

## Replacement workflow requirements

The only immediate replacement is a read-only local readiness workflow on `freedom-staging-runner`.

It must:

- require an exact typed confirmation such as `READ_ONLY_STAGING_CHECK`;
- use no staging SSH private key or host secret;
- perform no `sudo`, package installation, service enable/restart, database mutation, webhook mutation, deployment, symlink switch, or file write outside `$RUNNER_TEMP` and repository evidence paths;
- report only sanitized OS, disk, service state, PHP CLI/LSPHP, Composer, Docker, and runner facts;
- never print environment variables, credentials, `.env`, provider configuration, database rows, tokens, certificates, private paths, or full process command lines;
- use current pinned Actions and short timeouts;
- upload a short-retention sanitized artifact.

## Future controlled mutation requirements

A staging mutation workflow may be reintroduced only when its phase requires it and all of these are present:

- owner-approved exact purpose and rollback;
- protected GitHub Environment with approval;
- exact commit/release/checksum input;
- typed high-risk confirmation;
- environment and branch allowlist;
- concurrency lock preventing overlapping mutation;
- least-privilege account rather than unrestricted root where possible;
- secret presence checks that do not reveal values;
- preflight, backup/restore or rollback plan, timeout, cleanup, and sanitized evidence;
- no hard-coded obsolete verified SHA;
- current target contract note and accepted implementation CI before dispatch.

## Historical scripts

Files under `deploy/staging/` are retained as historical implementation/evidence inputs. They are not approved operational commands until referenced by a current guarded workflow/runbook. Add a header to each script before reuse stating current status, required arguments, privilege level, destructive effects, rollback, and last contract review.

## Current approved staging action

After cleanup, the only approved workflow is the read-only `Staging Readiness` diagnostic. It does not prove deployment readiness, provider compatibility, or production acceptance; it only records current host facts safely.
