# Requirement Traceability Contract

**Baseline:** Master Execution Prompt `1.0.0`  
**Document role:** stable traceability contract and canonical requirement coverage index.

Implementation status is intentionally not stored in this document. Current phase/task state belongs only in `PROJECT_STATUS.md`, `docs/project-status.json`, and live GitHub. Accepted implementation claims belong in bounded traceability/evidence records tied to exact commits and CI.

## Authority and purpose

`docs/specification/master-execution-prompt.md` is the normative product contract. `docs/01-authoritative-requirements.md` provides stable requirement IDs and concise acceptance outcomes.

This document defines how every requirement must become auditable without maintaining a second mutable project-status matrix.

A requirement is not accepted because a row, class, migration, interface, fake, test name, or document exists. Acceptance requires the repository verification lifecycle and evidence for the exact boundary being claimed.

## Traceability chain

For every bounded implementation or phase closure, the following chain must be discoverable:

`Requirement ID -> design/decision -> code boundary -> automated verification -> exact CI/result -> retained evidence -> review/integration boundary`

The chain may be many-to-many. One change can satisfy multiple requirements and one requirement can span multiple increments.

### Required traceability fields

A bounded traceability/evidence record must identify, as applicable:

- requirement ID(s);
- exact in-scope and out-of-scope behavior;
- design/ADR/contract source;
- implementation paths or module boundary;
- automated test paths or stable test identifiers;
- exact implementation/evidence commit boundary;
- exact CI run and result;
- retained artifact identity/digest where required;
- security/concurrency/provider/authorization limitations;
- remaining requirement gap when only a partial boundary is accepted.

`docs/development/increment-lifecycle.md` governs the exact acceptance lifecycle.

## Canonical §36 requirement coverage

The lists below are a stable coverage index only. They deliberately contain no live implementation state.

### Onboarding, identity, customers, agents, and access control

`ONB-001`, `ONB-002`, `ONB-003`, `ONB-004`, `ONB-005`  
`USR-001`, `USR-002`, `USR-003`  
`AGT-001`, `AGT-002`, `AGT-003`, `AGT-004`, `AGT-005`, `AGT-006`  
`ACL-001`, `ACL-002`, `ACL-003`

### Catalog and purchase

`CAT-001`, `CAT-002`, `CAT-003`, `CAT-004`, `CAT-005`, `CAT-006`, `CAT-007`, `CAT-008`  
`BUY-001`, `BUY-002`, `BUY-003`

### Payment authority and payment methods

`PAY-001`, `PAY-002`, `PAY-003`  
`C2C-001`, `C2C-002`, `C2C-003`, `C2C-004`, `C2C-005`  
`GFT-001`, `GFT-002`, `GFT-003`, `GFT-004`  
`USDT-001`, `USDT-002`, `USDT-003`  
`IPG-001`, `IPG-002`

### Wallet, promotions, and referrals

`WAL-001`, `WAL-002`, `WAL-003`, `WAL-004`, `WAL-005`  
`PRO-001`, `PRO-002`  
`REF-001`

### Panels, provisioning, and service lifecycle

`PRV-001`, `PRV-002`, `PRV-003`  
`SVC-001`, `SVC-002`, `SVC-003`, `SVC-004`, `SVC-005`, `SVC-006`, `SVC-007`, `SVC-008`, `SVC-009`, `SVC-010`, `SVC-011`, `SVC-012`, `SVC-013`, `SVC-014`

### Support, communication, content, and administration

`SUP-001`, `SUP-002`  
`COM-001`, `COM-002`, `COM-003`  
`CNT-001`, `CNT-002`, `CNT-003`  
`CHN-001`  
`ADM-001`, `ADM-002`

### Reporting, operations, backup, installation, update, security, and quality

`REP-001`, `REP-002`, `REP-003`  
`OPS-001`, `OPS-002`, `OPS-003`  
`BAK-001`, `BAK-002`  
`INS-001`  
`UPD-001`  
`SEC-001`  
`QUA-001`

## Additional non-functional requirement IDs

Architecture, data, runtime, localization, integration, security, and quality IDs outside the canonical §36 catalogue are defined authoritatively in `docs/01-authoritative-requirements.md` and must be cited by any bounded work they constrain.

They are not duplicated here because duplicating requirement definitions or mutable status creates drift.

## Evidence discovery

When reviewing a requirement:

1. read its acceptance outcome in `docs/01-authoritative-requirements.md`;
2. search bounded `docs/*traceability*.md` records and `evidence/` for the requirement ID;
3. inspect the cited implementation/evidence commits and exact CI;
4. inspect the live owning Issue/PR for remaining scope;
5. treat any uncovered acceptance clause as unresolved even if adjacent foundation work exists.

A global search result is navigation, not proof. The exact bounded evidence and repository state determine what is accepted.

## Anti-drift rules

- Do not add mutable implementation-status columns or phase-progress percentages here.
- Do not copy current SHAs, current CI runs, active branches, Worker state, blockers, or next-task instructions here.
- Do not redefine requirement acceptance outcomes here; update the normative requirement catalogue through an explicit scope decision instead.
- Do not mark a whole requirement satisfied from a partial foundation; bounded evidence must state the remaining gap.
- Preserve accepted historical evidence even after the current implementation head advances.
