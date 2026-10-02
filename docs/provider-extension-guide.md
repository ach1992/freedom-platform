# Version 1 Provider and Adapter Extension Guide

This guide defines the durable extension boundaries for external providers and service panels. It is for implementers and reviewers; it does not claim that a source contract has passed live compatibility against a specific deployed provider.

## Core rule

External systems must remain behind the owning application contract. Orders, wallet/ledger, provisioning and other business authorities must not learn a provider-specific API shape merely to add an adapter.

A new adapter must preserve:

- authoritative evidence boundaries;
- idempotency/stable operation identity;
- no-blind-retry behavior after an uncertain external effect;
- HTTPS/TLS and SSRF/redirect/DNS protections;
- bounded parsing/validation and secret-safe diagnostics;
- deterministic money/precision rules where financial;
- execution-time authorization for administrative mutation;
- test/fake support and failure-path coverage;
- existing reconciliation/manual-review semantics.

See [`07-security-threat-model.md`](07-security-threat-model.md), [`05-architecture-overview.md`](05-architecture-overview.md), and [`08-data-classification.md`](08-data-classification.md).

## Outcome semantics

Panel boundaries use `PanelOperationOutcome`:

- `success` - required provider result is authoritatively known;
- `definitive_failure` - operation is known not to have succeeded;
- `retryable_failure` - retry is allowed only through the owning bounded policy;
- `uncertain_result` - remote effect may have occurred; do not resend blindly.

Payment/provider contracts use their own evidence/result types but follow the same correctness principle: transport failure is not automatically business failure, and customer/browser assertions never become authoritative capture evidence.

## Service-panel adapters

Primary contract: `app/Modules/Panels/Application/Contracts/PanelAdapter.php`.

`PanelAdapter` owns connection testing, capabilities, authoritative lookup, create-equivalence identity, create/update/suspend/activate/delete/link rotation, sensitive delivery artifacts, synchronization and compatible-target discovery.

### Current source contracts

| Provider | Pinned source-contract version | Source-declared protocol capability |
| --- | --- | --- |
| Marzban | `0.8.4` | `shadowsocks`, `trojan`, `vless`, `vmess` |
| PasarGuard | `5.4.1` | `shadowsocks`, `trojan`, `vless`, `vmess`, `wireguard` |

Both source gateways declare `authoritative_username_lookup`, `fetch_status`, `list_compatible_targets`, `synchronize`, and `test_connection` capabilities.

These values describe the reviewed source contract only. **They are not live provider compatibility evidence.** Release acceptance must authenticate against the approved protected target, attest actual version/capabilities and exercise authorized acceptance paths before production activation.

### Adding or changing a panel adapter

1. Start from the generic `PanelAdapter` business contract and the provider's current authoritative API contract.
2. Keep credentials/endpoints in panel/session configuration; never hard-code them.
3. Implement a deterministic create-equivalence hash from fields the provider preserves and can later prove.
4. Implement authoritative lookup before allowing retry/recovery of uncertain create/mutation outcomes.
5. Map remote state to existing typed service/status results rather than leaking raw provider payloads upward.
6. Return safe provider codes/messages only; raw secrets/private payloads stay out of logs/audit/evidence.
7. Declare only capabilities/protocols proven by the implementation and supported contract.
8. Update provider-type/factory/config/persistence surfaces only where the actual supported provider requires them.
9. Add fake/contract/integration tests for success, definitive failure, retryable failure, timeout/lost-response uncertainty, malformed response, version mismatch and idempotent recovery.
10. Obtain protected live acceptance separately; source tests do not substitute for it.

## Automatic card-to-card verification

Primary contract: `app/Modules/Payments/CardToCard/Application/Contracts/BankTransactionVerificationProvider.php`.

The provider exposes `code(): string` and `fetch(?string $cursor): BankTransactionPage`. Existing runnable boundaries include `FakeBankTransactionVerificationProvider` and `GenericRestBankTransactionVerificationProvider`.

The application owns transaction observation, matching, duplicate/reordered handling, settlement authority and reconciliation. A provider should fetch/normalize observations; it must not decide that an order is paid by bypassing those authorities.

For a real provider:

- use a stable provider transaction/event identity;
- preserve integer IRR authority and exact provider-observed amounts;
- make pagination/cursor semantics deterministic;
- treat unavailable/ambiguous observations as non-authoritative;
- authenticate and validate the remote response;
- avoid logging account credentials or restricted banking payloads;
- prove duplicates and reordered observations cannot cause duplicate settlement.

## Gift-card verification

Primary contract: `app/Modules/Payments/GiftCard/Application/Contracts/GiftCardVerificationProvider.php`.

The contract exposes provider code/capabilities plus `validate`, `reserve`, `redeem`, `release`, and `status`. `GiftCardProviderCapabilities` states which operations a provider actually supports. Existing runnable boundaries include `FakeGiftCardVerificationProvider` and `GenericRestGiftCardVerificationProvider`.

Implementers must preserve the application reserve/redeem/release lifecycle and evidence authority. A timeout after a capture/reserve-like effect enters uncertainty/reconciliation; it must not trigger an automatic second external capture. Raw gift-card secrets and restricted evidence remain inside protected storage/transport.

## USDT/blockchain verification

Primary contract: `app/Modules/Payments/Usdt/Application/Contracts/BlockchainTransactionVerificationProvider.php`.

Existing implementations include Fake and Generic REST boundaries. Blockchain verification must preserve configured network/token/precision/confirmation rules and authoritative transaction identity. Fixed precision is required; binary floating-point is not money authority.

## SMS providers

Primary contract: `app/Modules/Identity/Application/Contracts/SmsProvider.php`.

An SMS adapter provides a code and `sendOtp(SmsOtpMessage): SmsDeliveryResult`. Identity owns OTP purpose, expiry, attempt/rate controls and verification state. SMS transport success alone does not authorize identity state outside that workflow.

## Generic REST versus provider-specific adapters

Use a Generic REST adapter when a provider can satisfy the existing normalized contract without provider-specific business semantics. Prefer a provider-specific adapter when authentication, lifecycle, idempotency, evidence or response semantics materially differ.

Do not expand a generic adapter until it becomes a hidden provider-specific implementation. A small explicit adapter is safer than condition-heavy shared code when external contracts truly differ.

## HTTP and network safety

Any configurable outbound provider endpoint must preserve the repository security contract:

- HTTPS and TLS certificate/hostname verification;
- typed/validated endpoint configuration;
- SSRF/private/reserved-address and DNS-rebinding controls;
- controlled redirect policy;
- bounded connect/request timeout;
- bounded response size/parsing;
- no credential/query/header leakage in logs;
- no arbitrary user-controlled URL fetch.

Reuse repository HTTP/security infrastructure rather than creating a provider-local client that bypasses these controls.

## Idempotency and uncertain effects

Before mutation, persist the application stable operation identity where the owning workflow requires it.

After timeout, connection loss or malformed response:

1. assume external effect is **unknown**, not failed;
2. use authoritative lookup/status/discovery when available;
3. compare result with persisted canonical/equivalence identity;
4. adopt/reconcile a proven existing effect;
5. retry only when owning policy proves retry safe;
6. otherwise retain manual-review/reconciliation state.

Never turn repeated transport attempts into the idempotency mechanism.

## Configuration and secrets

Provider configuration should contain only data the owning runtime needs. Secret values remain outside Git and must be masked/redacted in presentation, audit and diagnostic output.

When adding configuration:

- validate format and supported mode/version at the boundary;
- provide fail-closed defaults where activation is unsafe without explicit configuration;
- separate read/test capability from mutation activation where practical;
- update installer/preflight/deployment guidance only when operators genuinely need a new setting;
- never put real credentials or protected endpoints into fixtures, docs or release evidence.

## Testing and acceptance

Use the smallest evidence sequence that protects the contract:

1. pure/unit mapping/validation tests;
2. fake/generic provider contract tests;
3. real MariaDB/Redis integration where durable authority/idempotency/concurrency is involved;
4. exact-head repository CI;
5. protected target acceptance for a real provider when release scope requires it.

Protected live acceptance is not ordinary CI. It requires explicit target/credential ownership and action authorization. Do not use production customer, payment or service mutation merely to make source-level testing more realistic.

## Review questions

A reviewer should be able to answer from code/tests/evidence:

- Which application contract owns this adapter?
- What exact remote evidence is authoritative?
- What stable identity prevents duplicate external effect?
- What happens after a lost response?
- How are credentials/PII/private payloads protected?
- Which endpoint/TLS/SSRF controls are reused?
- Which capabilities/version assumptions are explicitly bounded?
- Does the adapter preserve existing financial/provisioning state authority?
- Are success, definitive failure and uncertainty tested?
- What protected live acceptance remains required?

If any material answer is unknown, do not infer production compatibility from a green source test.
