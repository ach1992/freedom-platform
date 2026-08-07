# Phase 0.4 Panel Provider Source Contracts

**Reviewed:** 2026-08-07  
**Status:** authoritative source-contract baseline; live provider acceptance intentionally deferred by owner  
**Requirements:** `PRV-001`, `PRV-002`, `PRV-003`, `SEC-001`, `SEC-002`, `QUA-001`

## Owner decision

Marzban and PasarGuard are not currently installed on the target server. Live connectivity, mutation, timeout, capability and compatibility testing is therefore **not an immediate Phase 0.4 dependency**.

The approved sequence is:

1. implement against the pinned upstream source contracts below;
2. prove request/response mapping, failure classification, TLS/redaction and remote-idempotency behavior with deterministic HTTP contract tests;
3. keep real targets disabled/fail-closed;
4. continue the remaining project phases without requiring temporary panel installations;
5. near final integration/release acceptance, the owner will provide dedicated test panels and protected credentials;
6. only then perform live connection, capability, create/adopt, mutation, uncertainty/discovery and cleanup tests.

No future chat or engineer should stop ordinary development merely because test panels are absent.

## Source precedence

Use provider behavior in this order:

1. exact pinned upstream provider source/tag;
2. upstream tests/models/routers for that tag;
3. Mirza Bot as a secondary implementation reference for proven endpoint sequencing and practical integration patterns;
4. local adapter/fake abstractions.

Mirza Bot is **not** authoritative when it conflicts with the pinned upstream provider source. Do not copy its credential storage, logging, TLS, retry or error-handling implementation into Freedom Platform.

## Pinned provider versions

### Marzban

- repository: `Gozargah/Marzban`
- tag: `v0.8.4`
- expected API family: legacy Marzban `proxies` / `inbounds` user model
- live acceptance: deferred until owner supplies a dedicated test installation

### PasarGuard

- repository: `PasarGuard/panel`
- tag: `v5.2.1`
- expected API family: `proxy_settings` / `group_ids` user model with RBAC and optional API-key authentication
- live acceptance: deferred until owner supplies a dedicated test installation

Do not silently switch to `latest`, `main`, another tag or a future provider version. A version change requires a focused contract review and tests.

## Secondary Mirza Bot reference

Repository: `mahdiMGF2/mirzabot`.

Relevant files at the reviewed source revision:

- `Marzban.php` — authentication, user lookup/create/update/delete, reset, subscription revoke, system/inbound/node calls;
- `panels.php` — higher-level service creation and normalization;
- `api/panels.php` — panel settings and older/newer Marzban payload switch;
- `README.md` — declares both Marzban and PasarGuard support.

Useful integration observations:

- authentication uses form-urlencoded username/password against `/api/admin/token`;
- the returned `access_token` is used as Bearer authentication;
- user lifecycle uses `/api/user` and `/api/user/{username}`;
- usage reset uses `/api/user/{username}/reset`;
- subscription rotation uses `/api/user/{username}/revoke_sub`;
- connection/capability inspection uses `/api/system` and `/api/inbounds`;
- the code distinguishes legacy `proxies`/`inbounds` payloads from newer `proxy_settings`/`group_ids` payloads.

The Mirza Bot implementation caches access tokens and contains project-specific persistence/error conventions. Freedom Platform must preserve its own encrypted credential model, safe result taxonomy, SSRF/TLS rules, exact replay behavior and redaction instead.

## Marzban v0.8.4 contract

### Authentication

`POST /api/admin/token`

Content type:

```text
application/x-www-form-urlencoded
```

Fields:

```text
username
password
```

Successful response includes `access_token`. Subsequent API requests use:

```text
Authorization: Bearer <access_token>
```

### Version and target discovery

- `GET /api/system` returns `version` plus system/user statistics.
- `GET /api/inbounds` returns inbound configurations grouped by protocol.

The adapter must compare the reported version with the reviewed contract before declaring the connection compatible.

### User lifecycle

- `POST /api/user` — create;
- `GET /api/user/{username}` — authoritative username lookup;
- `PUT /api/user/{username}` — modify;
- `DELETE /api/user/{username}` — delete;
- `POST /api/user/{username}/reset` — reset usage;
- `POST /api/user/{username}/revoke_sub` — rotate/revoke subscription identity;
- `GET /api/users` — list/query users when needed for diagnostic/reconciliation use.

### Create/update model

Important fields:

- `username`;
- `proxies`;
- `inbounds`;
- `expire` as UTC Unix timestamp or `0` for unlimited;
- `data_limit` in bytes, `0` for unlimited;
- `data_limit_reset_strategy`;
- `status` (`active` or `on_hold` during create; modification also supports disabled lifecycle);
- `note`;
- `on_hold_timeout`;
- `on_hold_expire_duration`;
- optional `next_plan`.

A successful `UserResponse` contains at least username/status/usage fields, `links` and `subscription_url`.

## PasarGuard v5.2.1 contract

### Authentication

Password authentication remains compatible with:

`POST /api/admin/token`

using OAuth2 form fields `username` and `password`, returning an access token used as Bearer authentication.

PasarGuard v5.2.1 also supports API keys:

- `X-Api-Key: pg_key_...`; or
- `Authorization: ApiKey pg_key_...`.

API keys can carry a permissions snapshot and are never Owner-level. For production, prefer a dedicated least-privilege API key when the final test panel supports the required permissions. Do not require the owner to paste the key into chat.

### Required permission surface

The final provider credential must be able to perform only the operations enabled for the configured target. Expected permissions include, as applicable:

- `system.read`;
- `users.read`;
- `users.create`;
- `users.update`;
- `users.delete`;
- `users.reset_usage`;
- `users.revoke_sub`;
- `groups.read` for target discovery.

If a configured operation lacks provider permission, capability discovery must mark it unavailable rather than retrying with broader credentials.

### Version and target discovery

- `GET /api/system` returns `version` and resource/user statistics;
- `GET /api/inbounds` returns available inbound names;
- `GET /api/inbounds/details` returns lightweight inbound metadata;
- `GET /api/groups` returns groups, including IDs/names/inbound tags/disabled state;
- `GET /api/groups/simple` provides lightweight group selection.

For PasarGuard, the natural Freedom Platform target reference is a validated Group ID plus its current inbound/capability snapshot. A stale/deleted/disabled group must fail closed.

### User lifecycle

Base router prefix is `/api/user`:

- `POST /api/user` — create;
- `GET /api/user/{username}` — authoritative username lookup;
- `PUT /api/user/{username}` — modify;
- `DELETE /api/user/{username}` — delete;
- `POST /api/user/{username}/reset` — reset usage;
- `POST /api/user/{username}/revoke_sub` — rotate/revoke subscription identity;
- `GET /api/users` — list/query users.

PasarGuard also exposes ID-based and explicit `by-username` variants; the adapter should prefer deterministic username lookup for the Freedom Platform create/adopt invariant and may retain numeric provider ID from the returned snapshot.

### Create/update model

Important fields:

- `username`;
- `proxy_settings`;
- `group_ids`;
- `expire` as UTC datetime or `0` for unlimited;
- `data_limit` in bytes, `0` for unlimited;
- `data_limit_reset_strategy`;
- `status`;
- `note`;
- `on_hold_timeout`;
- `on_hold_expire_duration`;
- optional `next_plan`;
- optional `hwid_limit` and other provider-specific fields only when explicitly supported by the Offering/target policy.

`UserResponse` includes provider numeric `id`, username/status, used/lifetime traffic, timestamps, `subscription_url`, `proxy_settings`, and group information.

## Freedom Platform mapping rules

### Remote identity

- deterministic Freedom Platform username is the authoritative create/adopt key;
- Marzban may not expose a separate durable numeric user ID in the v0.8.4 public response model; use the deterministic username as the stable provider identity where required;
- PasarGuard returns a numeric user `id`; store it as the provider remote ID while retaining deterministic username for authoritative lookup;
- canonical create hash is a Freedom Platform local invariant and must never be assumed to exist remotely.

Because provider APIs do not store the Freedom Platform canonical hash as an authoritative provider field, adoption must compare the remote snapshot fields that are actually observable and contractually controlled: username, target/group/inbound policy, allowance, expiry/status and any explicit validated create attributes. If complete equality cannot be proven, return conflict/manual review rather than silently adopting.

### Data allowance

- provider `data_limit=0` means unlimited;
- Freedom Platform `null` allowance maps to provider unlimited;
- `Set` writes the requested absolute provider limit;
- `Add` first performs authoritative lookup, computes the new absolute limit, then sends one guarded update; after an uncertain result it must rediscover before any additional mutation.

### Expiry

- Marzban v0.8.4 uses Unix timestamp/`0`;
- PasarGuard v5.2.1 accepts timezone-aware datetime or `0`;
- conversion occurs at the gateway boundary and all Freedom Platform stored timestamps remain UTC.

### Suspend/activate

Use provider-supported status transitions through `PUT /api/user/{username}`. The adapter must rediscover the resulting user snapshot and must not declare success from HTTP status alone when the returned/queried state is inconsistent.

### Delete

A provider `404` after an authoritative lookup is a definitive already-absent result for reconciliation purposes, not a reason to recreate automatically. Deletion remains idempotent at the Freedom Platform operation journal boundary.

### Subscription rotation and delivery

- rotate using `POST /api/user/{username}/revoke_sub`;
- rediscover the user after rotation;
- build `SensitiveDeliveryArtifacts` only from validated `subscription_url` / link fields;
- never log, audit, serialize or persist those values into ordinary evidence.

## HTTP result classification

The concrete gateway must map responses into the existing `PanelOperationOutcome` taxonomy.

### Definitive failure

Examples:

- authenticated 400 validation rejection;
- 403 permission denial;
- 404 authoritative user absence for lookup/mutation where absence is definitive;
- 409 duplicate/conflict response;
- version mismatch discovered from a successful `/api/system` response.

### Retryable failure

Use only when the failure is explicitly known to occur before a side effect and retry is safe, for example a local connection failure before request transmission where the HTTP client can prove no request was sent. Do not infer this casually.

### Uncertain result

Examples:

- timeout after request may have been transmitted;
- connection reset after request transmission;
- 5xx/502/503/504 after a mutation where provider side effect cannot be excluded;
- malformed success response after a mutating request.

An uncertain create/update/delete/reset/rotate result always enters authoritative discovery/reconciliation before any retry.

## Security requirements

- HTTPS only;
- TLS peer and hostname verification always enabled;
- existing `PanelEndpoint`, network policy and SSRF controls remain authoritative;
- redirects are disabled unless a future reviewed contract explicitly requires them and every redirect target is revalidated;
- no raw response body is copied into `safeMessage`, logs, audit or evidence;
- authentication tokens/API keys/passwords remain in `PanelCredentials`/encrypted storage and are redacted;
- token cache, if implemented, remains memory/cache scoped with bounded TTL and is invalidated on 401/credential change;
- credentials are never fetched into GitHub Issues, PR comments, chat or CI artifacts.

## Offline acceptance required before live testing

For each provider/tag, deterministic HTTP contract tests must prove:

- authentication request/response mapping;
- version compatibility success and mismatch fail-closed;
- target discovery mapping;
- authoritative username lookup: found, absent, unauthorized, malformed and transport failure;
- create payload and response mapping;
- exact-match adoption without create;
- mismatch conflict without create;
- timeout-after-create discovery with no second create;
- update expiry/data, reset, suspend, activate, delete and subscription rotation;
- 400/401/403/404/409/429/5xx classification;
- malformed JSON and truncated response handling;
- TLS/network policy remains enabled;
- no secret/delivery artifact appears in exception/log/debug serialization.

These tests may use Laravel HTTP fakes/local deterministic fixtures. They are source-contract evidence, not live-provider acceptance.

## Deferred live acceptance gate

When the owner provides test panels near final integration, record for each provider:

- exact installed version and build/revision if exposed;
- sanitized endpoint identity, never credentials;
- successful least-privilege authentication;
- `/api/system` version match;
- target/group/inbound discovery;
- create/adopt/conflict behavior using dedicated disposable usernames;
- update/reset/suspend/activate/rotate/delete;
- timeout/uncertainty test only through a controlled proxy/fault harness, never by blind duplicate request;
- cleanup proof showing no orphan remote account;
- sanitized retained artifact and exact application SHA.

Until then, real-provider targets remain disabled and no provider compatibility claim exceeds the pinned source-contract boundary.
