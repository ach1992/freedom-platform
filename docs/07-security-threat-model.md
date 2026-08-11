# Security Model

This document defines durable security boundaries and required controls. It does not claim that a control is implemented merely because it is listed here.

## Security objectives

1. Preserve financial and authorization integrity.
2. Prevent duplicate or unauthorized remote effects.
3. Protect secrets, identity/payment data, receipt media, and subscription material.
4. Fail safely when Telegram, Redis, providers, workers, storage, or network dependencies fail.
5. Preserve auditable and recoverable history without leaking restricted data.

## Trust boundaries

Treat as untrusted until authenticated/validated:

- Telegram updates and callback data;
- provider callbacks and API responses;
- administrator-supplied URLs/configuration/files/templates;
- queue/cache payloads;
- uploaded media;
- release and backup artifacts from storage/transport.

Administrator identity does not make input safe.

## Mandatory controls

### Authorization

- default deny;
- server-side execution-time authorization;
- explicit deny overrides grants;
- ownership checks in data/application boundaries, not only UI;
- replay-safe confirmation for sensitive operations;
- independent approval where policy requires it;
- immutable audit of privileged changes.

### Payment and financial integrity

- browser/customer evidence never proves capture;
- provider identity, amount, currency, destination, and consumption state are verified before acceptance;
- one external transaction/redeemable value cannot fund multiple effects;
- financial writes are transactional, idempotent, balanced, and append-only;
- ambiguity/reversal/uncertainty enters review/reconciliation instead of optimistic success.

### Webhooks and replay

- authenticate signature/secret against the raw request before business parsing;
- persist a stable event identity;
- acknowledge quickly and process asynchronously where appropriate;
- duplicate or out-of-order events cannot create duplicate durable effects.

### Outbound network / SSRF

Configurable outbound endpoints must:

- allow HTTPS only;
- reject credentials, malformed hosts, unsafe ports, and ambiguous encodings;
- reject loopback/private/link-local/reserved/metadata destinations;
- revalidate DNS/IP and every redirect target;
- preserve hostname/SNI and certificate verification;
- use bounded connect/response time and response-size limits;
- use provider/domain allowlists where possible.

TLS verification cannot be disabled. Private systems require managed CA/pinning, not `verify=false`.

### Secrets and restricted data

- secrets enter only through protected runtime channels;
- stored secrets are environment-backed, encrypted, or external references;
- saved secret values are write-only/masked;
- secrets/restricted values do not enter URLs, command arguments, logs, Issues/PRs, fixtures, CI artifacts, or repository evidence;
- key rotation is explicit and audited.

### Files

Uploads/private media are stored outside `public/`, randomly named, MIME/type/size validated, non-executable, and authorization-controlled. Do not trust the filename or client MIME type.

### Supply chain / backup / update

- locked dependencies and security/license scanning;
- release manifests/checksums/signatures validated before activation;
- backups encrypted and integrity-checked with separate key custody;
- restore/update fail closed on tamper, wrong key, or incompatibility;
- rollback never edits financial history to manufacture consistency.

## Blocking findings

A feature/release is blocked by any known unresolved condition such as:

- authorization bypass or IDOR;
- duplicate financial/provisioning effect;
- payment accepted from non-authoritative evidence;
- disabled TLS verification or exploitable SSRF;
- plaintext secret leakage;
- forged/replayed callback that changes state;
- unsigned/tampered update acceptance;
- backup that cannot be integrity-verified/restored;
- known exploitable Critical/High dependency/security issue without effective mitigation.

## Provider-specific security

Exact signatures, replay windows, authority semantics, certificate requirements, and error behavior must be re-verified against the provider's current official contract before activation. A fake, source inspection, or old integration note is not live-provider evidence.

Sensitive-data classes and retention constraints are in `08-data-classification.md`.