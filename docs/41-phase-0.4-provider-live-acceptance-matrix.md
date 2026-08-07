# Phase 0.4 Controlled Live Provider Acceptance Matrix

**Status:** deferred human gate; no row in this matrix is accepted without a controlled owner-supplied test panel.  
**Scope:** Marzban `v0.8.4` and PasarGuard `v5.2.1` only.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.

This matrix is the exact live acceptance gate that remains after deterministic read contracts, offline mutation contracts, create-equivalence reconciliation, fail-closed runtime review, and non-live Phase `0.4.0` reconciliation.

It must not be used to justify installing a temporary provider panel merely to manufacture evidence.

## Protected prerequisites

Before any row can move from `deferred` to executed:

1. owner supplies a controlled disposable test panel matching the exact pinned version/build or explicitly authorizes re-review of a different build;
2. endpoint and credentials are provided through protected runtime/secret configuration only;
3. credentials are never pasted into repository files, Issues, PR comments, evidence, logs, or chat handoffs;
4. TLS verification remains enabled;
5. a uniquely prefixed disposable test-user namespace is reserved for the run;
6. test credentials have the minimum permissions needed for the declared operation set;
7. cleanup/deletion of test users is explicitly permitted;
8. production Targets remain disabled until the full applicable matrix is accepted.

## Evidence rules

A live acceptance artifact may record only safe facts such as:

- provider code and exact reported version;
- operation name and sanitized outcome class;
- HTTP status where safe and useful;
- deterministic local operation/idempotency identifier;
- redacted/hashed test-user reference where needed for reconciliation;
- whether authoritative discovery observed absent/present/match/conflict;
- whether exactly one primary remote effect was observed;
- cleanup result;
- CI/run/artifact identifiers and digests.

It must not record:

- passwords, API keys or Bearer tokens;
- subscription URLs or proxy/config links;
- raw sensitive provider bodies;
- encrypted credential values or lookup hashes;
- production customer identifiers.

## Global safety sequence

Every provider run follows this order:

1. read-only authentication/version gate;
2. read-only target/capability discovery;
3. authoritative lookup of the unique disposable username and proof of initial absence;
4. create/adopt/conflict acceptance;
5. bounded mutation acceptance on the same disposable service;
6. delivery retrieval through the sensitive-artifact boundary;
7. explicit uncertainty/fault reconciliation proving discovery before retry;
8. delete/cleanup and authoritative proof of final absence;
9. only after all required rows pass may the corresponding Target/capability be considered for verified activation in a separate explicit acceptance change.

If an earlier row fails or becomes uncertain, later effectful rows stop until reconciliation determines authoritative state.

## Common acceptance matrix

| ID | Scenario | Required observation | Fail-closed result if not proved | Status |
|---|---|---|---|---|
| `LIVE-001` | Protected authentication | credentials work without appearing in normal logs/evidence | stop; no mutation | deferred |
| `LIVE-002` | Exact provider version | `/api/system` reports the pinned version/build accepted for this adapter | stop; no provider operation beyond safe diagnostics | deferred |
| `LIVE-003` | Read capability/health | read gateway performs connection/status checks with bounded transport and TLS verification | stop; Target remains disabled | deferred |
| `LIVE-004` | Target discovery | source-shaped compatible target/group is observed and maps deterministically to a local reference | stop; no Target activation | deferred |
| `LIVE-005` | Authoritative absent lookup | unique disposable username is authoritatively absent | no create if lookup unavailable/ambiguous | deferred |
| `LIVE-006` | Create from proved absence | exactly one remote service is created with intended preserved attributes | uncertain => discovery; never blind second create | deferred |
| `LIVE-007` | Exact-match adoption | authoritative remote state produces the same provider-specific `createEquivalenceHash` as local intent | missing proof => Manual Review; mismatch => conflict | deferred |
| `LIVE-008` | Existing mismatch/conflict | changed material preserved attribute is detected as conflict | no overwrite/recreate | deferred |
| `LIVE-009` | Idempotent replay | replay of the same accepted logical operation returns/preserves the original effect | no duplicate effect | deferred |
| `LIVE-010` | Conflicting idempotency reuse | same idempotency key with different fingerprint cannot replace the original effect | conflict/manual review | deferred |
| `LIVE-011` | Update expiry | authoritative read after mutation shows intended expiry | uncertain => discovery before retry | deferred |
| `LIVE-012` | Set data allowance | authoritative read shows exact positive absolute allowance | zero/unlimited ambiguity rejected | deferred |
| `LIVE-013` | Add data allowance | authoritative finite current limit is read first; absolute provider write equals old + delta | missing/unlimited/mismatched baseline => no mutation | deferred |
| `LIVE-014` | Reset usage | authoritative read confirms provider-supported reset semantics | uncertain => discovery before retry | deferred |
| `LIVE-015` | Suspend | authoritative read confirms non-active/suspended semantics accepted for provider | uncertain => discovery before retry | deferred |
| `LIVE-016` | Activate | authoritative read confirms active semantics | uncertain => discovery before retry | deferred |
| `LIVE-017` | Subscription rotation | explicit pinned revoke/rotation operation invalidates/changes provider subscription token/link without exposing it in evidence | unsupported/ambiguous => keep capability disabled | deferred |
| `LIVE-018` | Delivery retrieval | authorized delivery boundary receives expected sensitive artifacts while ordinary string/log/evidence views stay redacted | no artifact leakage | deferred |
| `LIVE-019` | Injected transport uncertainty | controlled timeout/disconnect after possible effect leads to authoritative discovery before any retry | no immediate repeated mutation | deferred |
| `LIVE-020` | Injected 5xx uncertainty | possible-effect 5xx is treated uncertain and reconciled before retry | no blind retry | deferred |
| `LIVE-021` | Rate limit before effect | proved pre-effect `429` follows bounded retry policy without duplicate effect | otherwise classify uncertain | deferred |
| `LIVE-022` | Delete | provider delete succeeds and subsequent authoritative lookup proves absence | uncertain delete => discovery/reconciliation | deferred |
| `LIVE-023` | Cleanup | all uniquely prefixed disposable test users/artifacts are reconciled and removed | Target remains disabled; gate incomplete | deferred |
| `LIVE-024` | Target activation eligibility | all required provider/version/capability rows have accepted evidence and connection state is current | Target remains `disabled` / capability unverified | deferred |

## Marzban `v0.8.4` provider map

Pinned source-shaped operations to exercise on the controlled panel:

| Operation | Pinned route/shape | Expected success boundary | Live-specific check |
|---|---|---|---|
| authentication | `POST /api/admin/token` | valid Bearer token used ephemerally | password/token absent from evidence/logs |
| version | `GET /api/system` | exact `0.8.4` | mismatch fails closed |
| authoritative username lookup | `GET /api/user/{username}` | 404 = absent; valid user JSON = present | provider failure is never absence |
| target discovery | `GET /api/inbounds` | compatible protocol/tag list | selected protocol/tag maps to exact opaque target reference |
| create | `POST /api/user` | HTTP `200` plus authoritative post-read equivalence | lookup-before-create and one remote service only |
| update expiry/data/suspend/activate | `PUT /api/user/{username}` | HTTP `200` plus authoritative post-read | request shape matches pinned mapper and no provider-generated secret enters equality |
| reset usage | `POST /api/user/{username}/reset` | HTTP `200` plus authoritative post-read | no blind retry after uncertainty |
| rotate subscription | `POST /api/user/{username}/revoke_sub` | HTTP `200` plus protected delivery re-read | raw subscription token/link never evidenced |
| delete | `DELETE /api/user/{username}` | HTTP `200` plus authoritative absence | uncertain result reconciled before retry |
| delivery | accepted Marzban delivery mapping | HTTPS subscription and supported config schemes only | delivery stays inside `SensitiveDeliveryArtifacts` |

Marzban create-equivalence must use only the accepted preserved intent fields: username, active status, data limit, expiry, reset strategy, selected protocol, and selected inbound tag assignment. Usage, subscription material, generated proxy secrets, and volatile timestamps are excluded.

## PasarGuard `v5.2.1` provider map

Preferred authentication for acceptance is a dedicated least-privilege `X-Api-Key` credential when the controlled environment supports it. Username/password token fallback may be tested separately without weakening least-privilege policy.

| Operation | Pinned route/shape | Expected success boundary | Live-specific check |
|---|---|---|---|
| API-key authentication | protected `X-Api-Key` | safe read succeeds | key absent from evidence/logs |
| password fallback | `POST /api/admin/token` | valid Bearer token used ephemerally | credentials/token absent from evidence/logs |
| version | `GET /api/system` | exact `5.2.1` | mismatch fails closed |
| username lookup | `GET /api/user/by-username/{username}` | 404 = absent; valid user JSON = present | failure is never absence |
| numeric remote-ID lookup | `GET /api/user/by-id/{id}` | authoritative same user | numeric identity remains explicit |
| target discovery | `GET /api/groups` | enabled compatible groups only | disabled groups cannot become Targets |
| create | `POST /api/user` | HTTP `201` plus authoritative post-read equivalence | lookup-before-create and one remote service only |
| update expiry/data | `PUT /api/user/by-id/{id}` | HTTP `200` plus authoritative post-read | additive path reads finite baseline first |
| suspend/activate | `PUT /api/user/by-id/{id}/disabled` | HTTP `200` plus authoritative status read | pinned boolean semantics confirmed |
| reset usage | `POST /api/user/by-id/{id}/reset` | HTTP `200` plus authoritative post-read | uncertainty reconciled first |
| rotate subscription | `POST /api/user/by-id/{id}/revoke_sub` | HTTP `200` plus protected delivery re-read | raw subscription token/link never evidenced |
| delete | `DELETE /api/user/by-id/{id}` | HTTP `204` plus authoritative absence | uncertain result reconciled before retry |
| delivery | accepted PasarGuard delivery mapping | HTTPS subscription URL only | URL credentials forbidden; artifact stays sensitive |

PasarGuard create-equivalence must use only the accepted preserved intent fields: username, active status, data limit, expiry, reset strategy, and selected group IDs. Usage, subscription URL, generated proxy secrets, timestamps, and unrelated response metadata are excluded.

## Fault-injection acceptance detail

A fault scenario is valid only when it can distinguish these cases safely:

- failure proved before request/effect: may be classified retryable under the accepted policy;
- request may have reached provider: `uncertain_result`;
- after uncertainty: perform authoritative lookup/status discovery first;
- if discovered exact effect exists: adopt/reconcile; do not repeat effect;
- if discovered mismatch exists: conflict/manual review;
- if discovery itself is unavailable: stop/manual review;
- only a proved authoritative absence/state permitting the operation can allow a later retry.

The fault harness must record the number of provider mutation attempts so evidence can prove no immediate duplicate remote effect.

## Target activation gate

Source mapping, offline fixtures, or a successful subset of live rows never activates a Target automatically.

A later explicit activation change must prove all of the following at execution time:

- exact provider/version acceptance is current;
- Panel Connection is active and latest connection test succeeded;
- required capability evidence is verified rather than declared;
- selected Target is active only after owner-approved live evidence;
- protocol/profile assignment is active and compatible;
- route operational verifier accepts the complete chain.

Until then, all real provider Targets remain fail closed.

## Human dependency

All non-live rows are specified. The remaining dependency is owner provision of controlled Marzban `v0.8.4` and PasarGuard `v5.2.1` test panels (or explicit approval to re-review a different exact build) with protected credentials and permission to create/delete disposable test users.

This is the Phase `0.4.0` live-provider human gate; it is not permission to expose credentials or enable production Targets.