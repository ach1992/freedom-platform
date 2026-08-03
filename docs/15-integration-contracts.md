# Integration Contracts

**Status:** architecture contracts. Provider-specific request/response shapes, current official status mappings, limits, and signatures must be recorded in dated contract notes and tested before production activation.

## Contract rules shared by all adapters

- Core/Application code depends on ports and normalized DTOs, never provider SDK response objects.
- DTOs are immutable, typed, unit-explicit, and contain no binary float money.
- Every mutating call receives a stable local operation ID and provider idempotency key where supported.
- Outcomes are one of `Success`, `DefinitiveFailure`, `RetryableFailure`, or `UncertainResult`. A timeout after request transmission is normally uncertain, not definitive failure.
- Adapters declare capabilities and reject unsupported operations before side effects.
- Transport uses HTTPS, certificate/hostname verification, bounded connect/overall timeouts, response-size limits, bounded retry with jitter, and a circuit breaker.
- Adapters never write Order, Ledger, Payment, or Service tables. They return evidence to the owning Application Service.
- Secrets enter through a secret reference/value object, are never serialized into jobs/events, and are redacted from exception/log context.
- Provider payloads are schema-validated and normalized. Store an encrypted raw payload only when operationally necessary; always allow a canonical payload hash and safe evidence summary.
- Contract tests cover current configured/live version separately from deterministic fakes.

## Common value objects

```text
OperationId       opaque stable application identifier
IdempotencyKey    scoped, stable, non-secret token
Money             integer amount + explicit ISO/internal currency (IRR for fiat)
CryptoAmount      fixed decimal string + asset + network + scale
ProviderRef       provider configuration ID + normalized external ID
OccurredAt        timezone-aware instant normalized to UTC
SafeEvidence      allowlisted codes/IDs/hashes/masked values; no secret payload
```

## Error mapping

| Provider condition | Normalized result | Retry rule |
|---|---|---|
| Local validation/auth configuration error | `DefinitiveFailure` | No retry; disable/test configuration |
| Explicit provider 4xx business rejection | `DefinitiveFailure` | No retry unless contract names a transient code |
| Explicit 429/temporary 5xx before known effect | `RetryableFailure` | Honor `Retry-After`; bounded jitter |
| Connect failure before request transmission can be established | `RetryableFailure` | Bounded retry |
| Read timeout/connection loss after mutating request | `UncertainResult` | Query status/discover by operation identity |
| Malformed/oversized/signature-invalid response | `SecurityViolation` or definitive integration failure | No blind retry; alert/circuit |
| Unknown provider status | `UncertainResult` | Persist raw hash/safe evidence; reconcile/manual review |

## Service panel port

`PanelAdapter` capabilities:

```text
testConnection, describeVersion, health, discoverCapabilities,
listCompatibleTargets, createService, findByRemoteId,
findByDeterministicUsername, fetchService, updateExpiry,
setOrAddDataAllowance, resetUsage, suspend, activate, delete,
rotateSubscriptionLink, getDeliveryArtifacts, synchronize
```

Required request fields: panel/target IDs, local operation ID, deterministic normalized username, explicit expiry/data/unit, typed protocol/service-mode attributes, and expected previous version when modifying. Required result fields: normalized remote ID, verified attributes, capability/version snapshot, sanitized evidence, and outcome certainty.

`MarzbanAdapter` and `PasarGuardAdapter` implement this port independently; semantic differences remain capability flags and adapter translations. `FakePanelAdapter` supports deterministic success, definitive failure, timeout-before-create, timeout-after-create, existing-match, existing-conflict, and version/capability changes.

Remote retry rule: after any uncertain create/update, call lookup/status using remote ID, provider idempotency support, and deterministic username. Adopt only an exact compatible match; conflict enters reconciliation. Never immediately create again.

## Payment provider port

`PaymentProvider` exposes the supported subset of:

```text
validateConfiguration, testConnection, capabilities,
createIntent, presentInstructions, acceptSubmission,
verifyWebhook, fetchAuthoritativeStatus, normalizeTransaction,
cancelOrExpire, refund, reconcile, health
```

- `verifyWebhook` receives raw body, relevant headers, method, route/provider configuration, and receipt time. It returns verified event identity and normalized event only after signature/replay checks.
- `createIntent` carries order ID, exact amount/currency/unit, callback identifier, expiry, and operation key. It returns provider ID and presentation data, not a paid result.
- `fetchAuthoritativeStatus` is required before a sensitive capture when the provider contract makes callbacks/returns non-authoritative.
- `refund` returns a unique provider refund ID and captured amount; local cumulative ceiling is checked before and after call.
- Zarinpal browser return and NOWPayments redirect are never authority. Exact official API shapes/signature canonicalization remain provider contract-note work.

## Bank transaction verification port

```text
testConnection()
capabilities() -> pull/webhook/detail fields and authority semantics
pullTransactions(cursor, fromUtc, toUtc, limit)
fetchTransaction(externalTransactionId)
verifyWebhook(rawRequest)
normalizeTransaction(payload, mappingVersion)
health()
reconcile(period)
```

Normalized transaction requires provider config/transaction/event IDs, integer IRR amount and source unit, UTC occurred/settled times, authoritative status, destination keyed hash/mask, optional sender keyed hash/mask/name, reference, redacted description, payload hash, ingestion method, first/last seen, and reconciliation state.

`GenericRestBankTransactionProvider` supports only declarative allowlisted JSON paths and transforms (`trim`, `digits-only`, date parse, explicit IRR/Toman conversion, status map, hash/mask). It cannot run expressions, templates, PHP, SQL, or shell. Endpoint access uses the central SSRF-safe client. Cursor writes occur only after the batch is durably normalized; overlap polling handles delayed settlement/reversal.

Automatic capture requires settled status, exact payable amount, destination, valid time/late policy, unused transaction, eligible intent/order, and one unambiguous candidate. A score can explain/strengthen but cannot bypass hard constraints.

## Gift-card verification port

```text
testConnection, capabilities, validateCode, validateImage,
reserveOrLock, redeemOrCapture, releaseOrCancel,
queryStatus, verifyWebhook, normalizeResult, health, reconcile
```

The contract distinguishes `valid_unreserved`, `reserved`, and `captured`. Only atomic reservation/capture or an explicitly approved equivalent authority can authorize automatic financial capture. Code/application operation IDs make validate/redeem calls idempotent. External redemption ID and local code keyed hash are unique.

`GenericRestGiftCardVerificationProvider` uses the same safe mapping, SSRF, secret, TLS, body-size, and status-unknown rules as the bank provider. OCR/image parsing alone is not authority. The fake covers validation-only, reservation, capture, response-loss/status recovery, already-used, mismatch, pending, and outage.

## Blockchain transaction verification port

`BlockchainTransactionVerificationProvider` normalizes network/chain ID, canonical TXID, token contract/asset, destination, fixed-decimal amount, block/confirmation count, status, observed time, and reorg/reversal evidence.

For direct USDT v1:

- network shown and validated as BEP20;
- unique `(chain_id, normalized_txid)`;
- destination, token asset, exact quote amount, confirmation policy, and time must match;
- under/over/partial/wrong-network/late/reorg results enter manual review;
- receipt image is never authority.

No specific explorer/node API is assumed until credentials and official contract are supplied.

## Rate provider port

`RateProvider.fetch(asset, quoteCurrency, side)` returns fixed-decimal raw IRR rate, source ID, observed/fetched UTC times, response hash, and authority/staleness metadata. It never applies business margin. The pricing service applies configured side, margin, sanity bounds, secondary-source divergence, cache age, and quote expiry, then snapshots all inputs.

Implementations required later: manual, Nobitex public market data, and Tetherland price data. Endpoint/status/limit schemas must be re-verified against current official sources.

## SMS provider port

`SmsProvider.sendOtp(destinationRef, templateRef, otpTransientValue, operationId)` returns provider message ID and a delivery certainty classification. Melli Payamak is primary and Kavenegar fallback. An uncertain primary timeout never triggers fallback because it may send two codes/messages. OTP plaintext must not enter the queue: generate in the sending boundary or protect a short-lived delivery envelope under an explicit design.

Provider health, template validation, rate limit and definitive/uncertain error mapping are capabilities/contract-note fields.

## Telegram boundary

Inbound webhook contract:

- HTTPS `POST`, expected content type and bounded body;
- constant-time secret-token check before durable acceptance;
- normalized update with unique `(bot_id, update_id)`;
- quick acknowledgement after idempotent persistence; non-trivial processing queued;
- raw update not stored by default.

Outbound contract accepts safe rendered content, target internal reference, parse mode, media reference, keyboard definition, correlation/operation ID. It records Telegram message ID and delivery attempts. It respects `retry_after`, classifies blocked/chat-not-found/invalid-content as permanent as appropriate, and never treats delivery as a financial rollback.

Callback tokens are server-side records: random token hash, actor/chat/action/target binding, parameters fingerprint, expiry, use/replay policy, and current aggregate version.

## External endpoint configuration schema

Any administrator-configurable provider endpoint includes:

- normalized HTTPS base URL and allowlisted ports/domains;
- authentication type from a closed enum: Bearer, API-key header, Basic, HMAC, or safely implemented mTLS;
- encrypted secret references and allowlisted static header names (reject hop-by-hop/host/content-length overrides);
- connect/overall timeout, response/body/decompression limits, rate behavior;
- redirect policy off by default; custom CA/pin with rotation metadata if required;
- versioned safe field/status/unit/timezone mappings;
- test/live state, last contract test, health/circuit state.

All DNS answers and redirects are checked under `07-security-threat-model.md`. No arbitrary IP literal, local path, Unix socket, proxy override, or non-HTTPS scheme.

## Webhook ingestion transaction

1. Resolve provider by secret, non-enumerable route identifier.
2. Enforce method, size, content type, and rate controls.
3. Verify provider signature over raw request bytes before business parsing.
4. Normalize/validate event identity and timestamp/nonce policy.
5. Insert unique event with payload hash and minimal encrypted raw evidence if approved; duplicate returns success without another effect.
6. Insert outbox processing request in the same transaction; acknowledge quickly.
7. Worker re-reads current intent/order, optionally queries authoritative status, and invokes the single capture service.

## Contract note template

Each configured integration must add a dated note containing:

- provider/product and tested API/panel version;
- official documentation URL and verification date;
- environment/base host (no private credential or URL if sensitive);
- authentication and signature algorithm/canonicalization;
- request/response schemas and redacted samples;
- amount/currency/time/status mapping;
- idempotency and uncertain-result recovery;
- timeout/retry/rate limits/circuit policy;
- webhook event identity/replay window;
- TLS/custom CA/pin and rotation;
- capability matrix and unsupported operations;
- fake/contract/sandbox/live evidence paths.

## Activation gate and unknowns

A provider remains disabled until configuration validation, SSRF/TLS checks, fake tests, current-contract tests, health check, status/amount/unit mapping review, secret redaction test, and authorized activation pass. Real bank/gift providers, installed panel versions, blockchain verifier, production credentials, source allowlists, and provider-specific signature schemes are not yet supplied; this document does not claim integration or test completion.
