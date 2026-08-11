# Data Classification and Handling

This document defines durable data-handling rules. Final legal retention periods require owner/legal approval before production use.

## Classes

| Class | Examples | Minimum handling |
|---|---|---|
| `PUBLIC` | published labels, public guides | integrity and output-encoding controls |
| `INTERNAL` | health summaries, non-secret release metadata | authenticated staff access; no public exposure |
| `CONFIDENTIAL` | account/order history, Telegram identity links, masked profile data | least privilege, encrypted transport/backups, no routine content logging |
| `RESTRICTED` | credentials, OTPs, full identity/payment data, gift codes, receipts, subscription URLs, raw signed provider payloads | encryption/reference, write-only or audited reveal, private storage, minimal retention, never routine logs |

A record inherits the highest class of any included field. Hashing is not anonymization when the value space is searchable.

## Core rules

- Store only data required for a defined product, security, accounting, or operational purpose.
- Production data is forbidden in developer fixtures, local databases, screenshots, Issues, PRs, CI, or repository evidence.
- Sensitive searchable identifiers use versioned keyed hashes; ordinary unsalted hashes are not sufficient.
- Full restricted values are masked by default and revealed only through a narrowly authorized/audited path when genuinely required.
- Private files, backups, and subscription material remain outside the public web root.
- Queue, cache, outbox, alerts, exceptions, audit, and logs carry identifiers/references rather than restricted values whenever possible.
- Provider payloads are normalized immediately; raw payload retention is exceptional, encrypted, and time-bounded.
- Financial and audit history is append-only; deletion/anonymization must not corrupt accounting or security evidence.

## Typical handling

| Data | Handling |
|---|---|
| Telegram user/chat ID | confidential routing identifier; log only when operationally needed |
| Phone / national ID / PAN / IBAN | encrypted canonical value when full recovery is required; keyed hash for exact lookup; masked display |
| OTP | plaintext never persisted; store only verification state/hash and expiry/attempt counters |
| Credentials/API keys/tokens | environment, encrypted secret, or external reference; never display/log full value |
| Receipt/identity media | restricted private random path; authorized resend/stream only |
| Gift-card/redeemable code | keyed identity hash; encrypted recoverable value only when provider/manual workflow truly requires recovery |
| Subscription/config URLs | restricted; encrypted/reference storage; owner/authorized delivery only |
| TXID/provider transaction ID | normalized unique evidence; permission-scoped lookup |
| Ledger/payment/refund/order snapshot | confidential and integrity-critical; relational immutable history |
| Raw provider webhook/API payload | do not retain by default; encrypted bounded retention only for a defined dispute/reconciliation need |
| Audit record | append-only, redacted, no source secrets |
| Backup | authenticated encrypted archive + manifest; key kept separately |

## Retention policy

Until a final legal/business policy is approved:

- ephemeral tokens/OTP state: shortest operational period;
- raw Telegram/provider payloads: do not retain by default;
- private media/evidence: bounded by review/dispute need;
- application/security logs: bounded operational retention and no restricted payloads;
- exports: short expiry, retain only audit metadata;
- financial, settlement-consumption, finalized order snapshots, and administrator/security audit: long-term append-only according to approved accounting/legal policy;
- backups: encrypted rotating retention with restore validation and legal-hold exception.

Deletion from the live database does not immediately erase retained encrypted backups. Restore procedures must reapply current retention/tombstone rules before normal access where applicable.

## Owner decisions required before production

- jurisdiction/accounting/privacy retention duties;
- which optional identity/payment fields are actually necessary;
- retention periods and data-subject/legal-hold process;
- roles allowed to reveal restricted values and whether dual approval is required;
- backup/signing-key custody and recovery.

Until these are decided, optional restricted collection and raw-payload retention remain disabled by default.