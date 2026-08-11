# Durable Risk Register

This file lists cross-project risks and owner decisions that remain relevant across multiple tasks. Task-specific risks belong in the owning GitHub Issue/PR, not in separate Markdown files.

| ID | Durable risk | Required control |
|---|---|---|
| `RSK-001` | duplicate callback/job/operator action creates a second financial or remote effect | stable idempotency, DB uniqueness/locks, guarded transitions, replay/concurrency tests |
| `RSK-002` | provider mutation succeeds but response is lost | persist operation identity; authoritative lookup/discovery/reconciliation before retry |
| `RSK-003` | wallet/payment concurrency corrupts balances or settlement | balanced append-only ledger, deterministic lock order, MariaDB contention tests |
| `RSK-004` | paid item provisions more than one remote service | unique operation/item identity, deterministic remote identity, discovery/adoption on uncertainty |
| `RSK-005` | configured endpoint enables SSRF/DNS rebinding/MITM | HTTPS-only typed endpoint, DNS/IP/redirect validation, allowlists, TLS verification |
| `RSK-006` | provider contract/version differs from assumed semantics | current official/installed contract verification and controlled acceptance before activation |
| `RSK-007` | payment evidence is mistaken for authoritative capture | provider-specific authority contract; validation/browser/customer evidence never equals capture |
| `RSK-008` | IRR/Toman/crypto conversion causes wrong settlement | integer IRR, explicit display unit, fixed-precision crypto, immutable rate/price snapshots |
| `RSK-009` | secret/PII/payment/subscription data leaks | least privilege, encryption/keyed hashes, masking, redaction, negative leakage tests |
| `RSK-010` | compromised administrator performs sensitive action | execution-time default-deny authorization, replay-safe confirmation, optional dual approval, audit |
| `RSK-011` | forged/replayed provider or Telegram webhook changes state | authenticate raw request, stable event identity, replay protection, authoritative re-query where required |
| `RSK-012` | malicious upload/private evidence exploits parser/storage | private non-executable storage, MIME/size allowlist, randomized names, authorized delivery |
| `RSK-013` | tampered release/backup corrupts or executes code | signed/checksummed release metadata, authenticated-encrypted backup, compatibility checks |
| `RSK-014` | code rollback is incompatible with database schema | expand/contract migrations, compatibility metadata, verified backup/restore path |
| `RSK-015` | backup exists but is not restorable | periodic isolated restore rehearsal, integrity checks, measured recovery result |
| `RSK-016` | Redis/worker/provider outage becomes correctness failure | durable MariaDB barriers, bounded retry, queue isolation, health/reconciliation paths |
| `RSK-017` | dependency/supply-chain issue enters release | lockfile, advisory/abandonment/license checks, reviewed updates, integrity controls |
| `RSK-018` | unknown workload invalidates capacity assumptions | instrumentation and target-like performance/backlog testing before release certification |
| `RSK-019` | retention policy either leaks data or destroys required evidence | purpose-limited retention, immutable finance/audit exceptions, legal hold, encrypted backups |

## Owner decisions required before production

- real bank/gift-card/provider authority contracts and credentials;
- high-value/dual-approval thresholds;
- final privacy/accounting retention policy and reveal permissions;
- target workload/SLO and recovery objectives;
- installed Marzban/PasarGuard versions/capabilities;
- signing-key and backup-key custody.

Until an owner/provider decision exists, use a conservative fake/fail-closed/default-disabled path and do not claim production readiness.

## Risk handling

Critical/High financial, authorization, security, provider, schema, backup/restore, or release risk cannot be accepted implicitly. The owning Issue/PR must identify the concrete control/evidence, and explicit owner acceptance is required when material residual risk remains.