# Phase 0.4 Protocol Profile, Service Target and Sales Server Foundation

Status: **Implementation verified — evidence-head CI pending**

## Accepted implementation

- SHA: `7c48399a2be8a79ddb078f79684d73dd9869b322`
- CI run ID: `31059517586`
- CI run number: #811
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 227 tests, 1121 assertions
- test artifact: `test-evidence-31059517586`
- test artifact digest: `sha256:e86440c197119403c49aa7137346dff3b2b37af08276bb56cadd06cfc4459d13`

## Verified boundary

- typed Protocol Profile definition for protocol family, transport, security layer, host, SNI, path, port and flow;
- explicit Profile lifecycle with active-definition immutability and dependency-safe archival;
- encrypted, versioned Service Target configuration tied to one Panel Connection;
- typed target kinds: `inbound`, `group`, `template`, `host`;
- normalized declared capability rows and Target-to-Profile compatibility assignments;
- customer-selectable profiles only when assigned and active;
- localized Sales Server identity, ordering, lifecycle and hidden/listed visibility;
- existing `panels.manage` authorization for non-secret inventory and `panels.manage_secrets` plus Sensitive Action Approval for target configuration;
- exact replay receipts, payload-conflict rejection, row locks and optimistic versions;
- FK-backed append-only histories and safe structural audit data;
- MariaDB checks and triggers preventing unverified Target activation and unsafe dependency archival;
- explicit short MariaDB constraint names to remain within the 64-character identifier limit.

## Security evidence

Target configuration contains remote identifiers and typed routing material. It is encrypted at rest. Audit and history retain only structural metadata such as IDs, kind, counts, state, visibility and version. They do not retain target remote identifiers, host/SNI/path values, encrypted blobs, configuration hashes, localized names or mutation HMACs.

The implementation suite verified encryption/decryption behavior, permission separation, Sensitive Action Approval consumption, exact replay, payload-conflict rejection, direct-SQL activation rejection, dependency-safe archival and secret-free audit output.

## Failure history and remediation

- run #808: Pint identified three formatting-only files; the exact formatter output was applied;
- run #809: PHPStan identified a missing `$tableName` closure capture; the migration was corrected and dynamic trigger-name interpolation was removed;
- run #810: MariaDB rejected a generated 66-character FK name; history-table parent constraints were given explicit short names;
- run #811: every mandatory CI job passed.

No test, authorization check, encryption control, trigger or activation gate was removed or weakened during remediation.

## Retained artifacts

- preflight: `preflight-evidence-31059517586`, digest `sha256:65c5ab6c2493161ca08bec7c6a665b3509354b769f1d6332f73690c451842325`
- secret scan: `gitleaks-results.sarif`, digest `sha256:deda186218e5205e4b2b64ce833e003bc58c642f0e713fceeca81ecad45cb8e1`
- static: `static-evidence-31059517586`, digest `sha256:cc2bc8f4931bad75a1e231a7ecf8bac00dfda6317c23f9c5debccd963aa3ffe1`
- dependency: `dependency-evidence-31059517586`, digest `sha256:2173db6ac3ff3c11710233c012c32c0a52d0161d585ee483acee86f1c8f77627`
- tests: `test-evidence-31059517586`, digest `sha256:e86440c197119403c49aa7137346dff3b2b37af08276bb56cadd06cfc4459d13`

## Intentionally excluded

- adapter test connection, version discovery, health or capability verification;
- Service Target activation and operational HTTP/TLS/SSRF behavior;
- capacity, availability, selection and fallback;
- Plan Offering commercial configuration;
- custom-plan, trial, order, payment and provisioning behavior.

A Target remains `disabled` with `declared` capabilities. Database triggers reject `active` Target rows until the later adapter-contract increment replaces that provisional gate with verified evidence rules.

## Evidence-head gate

The commit containing this evidence must pass the same complete CI workflow. Issue #7 and PR #6 remain unchanged until that evidence-head run is Green.
