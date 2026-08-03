# Data Classification and Handling

**Status:** design baseline. Retention values marked “provisional” require Owner/legal approval before production data collection.

## Classification levels

| Level | Meaning | Examples | Minimum handling |
|---|---|---|---|
| `PUBLIC` | Intended for anyone | Published product labels, public guide URLs | Integrity controls; safe output encoding |
| `INTERNAL` | Operational, low sensitivity | Health summaries, feature flags, non-secret release metadata | Authenticated staff access; no public indexing |
| `CONFIDENTIAL` | Personal/business data with material privacy impact | Telegram ID linked to profile, ticket text, masked phone/card, order history, provider summaries | Least privilege, access audit where sensitive, encrypted transport/backups, no debug logging |
| `RESTRICTED` | Secret or directly exploitable/high-impact data | Tokens/passwords/keys, OTP, full identity/card/gift code, receipt files, subscription URLs, raw signed provider payloads | Encryption/reference, write-only or explicit reveal, strict permission, audit, private storage, minimal retention, never routine logs |

Classification follows the highest-sensitivity field in a record or payload. Hashing is not anonymization when values have a small/searchable domain.

## Data inventory

| Data | Class | Stored form | Lookup/presentation | Log/audit rule |
|---|---|---|---|---|
| Telegram numeric user/chat ID | Confidential | Plain numeric identifier; needed for routing | Exact only to authorized operations; normal staff view may mask profile context | May log ID only when operationally needed, never raw update |
| Telegram username/name/language | Confidential | Plain normalized profile fields | Escaped; username is not stable identity | Avoid in routine logs; audit changes if material |
| Phone number | Restricted | Encrypted canonical E.164/local normalized value plus versioned keyed hash | Masked by default; exact search via keyed hash | Reveal/release audited; never log full value |
| OTP | Restricted/ephemeral | Slow/secure keyed hash only, expiry and counters | Never display after issuance | Never log or backup plaintext |
| National ID | Restricted | Encrypted normalized value plus versioned keyed hash | Masked; explicit permission/reveal | Reveal audited; never log |
| Bank card/PAN, IBAN, account | Restricted | Encrypted value plus keyed hash when match/search is needed | Masked; full reveal only narrowly if justified | Never log; reveal/change audited |
| Receipt/identity image | Restricted | Private random path, Telegram IDs, safe metadata, optional content hash | Authorized resend/stream only | No public URL/path or content in log/audit |
| Gift-card code/serial | Restricted | Encrypted only when provider/manual use requires it; keyed normalized hash for uniqueness | Mask after first entry; audited explicit reveal | Never log, export routinely, or include in jobs |
| Provider/API/bot/DB/Redis secret | Restricted | `.env`, encrypted secret value, or external reference | Write-only status/fingerprint | Never log/audit value; audit metadata change |
| Webhook signature/raw body | Restricted while actionable | Verify raw bytes; encrypted payload only if necessary; canonical hash/event ID | Safe normalized evidence | Never log raw signed payload by default |
| Subscription URL/token/config | Restricted | Encrypted sensitive material | Deliver only to owner/authorized admin; mask otherwise | Never log/alert/report full value |
| Wallet address/TXID | Confidential; address becomes Restricted when linked identity policy requires | Destination config encrypted if business treats it as sensitive; TXID plain unique evidence | Mask wallet; TXID exact search permission-scoped | No full address in routine logs |
| Order/payment/ledger/refund | Confidential, integrity-critical | Relational records; append-only financial posting | Permission-scoped and unit-labelled | IDs/correlation allowed; no embedded sensitive payload |
| Provider transaction evidence | Confidential/Restricted by fields | Normalized columns; encrypted raw payload only when necessary | Mask sender/destination; safe reason list | Log provider/event IDs and state, not payload |
| Panel credential | Restricted | Encrypted/reference | Write-only test status | Never log |
| Remote service ID/username | Confidential | Normalized identifiers | Owner/admin scope | May log internal/remote IDs; not subscription token |
| Ticket/direct message/broadcast content | Confidential (may become Restricted) | Private relational/media storage | Ownership/role checks | Log metadata, not message body by default |
| IP address/user agent/session | Confidential | Minimized, normalized; keyed/truncated where sufficient | Security-only access | Retain briefly; avoid broad reports |
| Audit log | Confidential, integrity-critical | Append-only structured fields and redacted before/after hashes/summaries | Authorized audit views | Must not contain source secrets |
| Backup | Restricted | Authenticated encrypted archive + signed/checksummed manifest | Authorized restore only | Log manifest ID/checksum/result, not contents/key |
| Release package | Internal until public decision | Immutable signed/checksummed artifact, no secrets | Operator access | Safe manifest metadata may be logged |

## Encryption, hashing, and masking

- Application encryption uses authenticated encryption with key version metadata. Ciphertext and its key are never exported together under the same custody policy.
- Searchable identifiers use a versioned keyed hash (`HMAC` with a dedicated lookup key), not an unsalted ordinary hash. Rotation supports old/new lookup key versions during a bounded migration.
- OTPs are verified against a stored secure keyed hash and attempt state; plaintext exists only transiently for delivery.
- Content hashes are used for deduplication/evidence, not as proof of ownership or authenticity.
- Standard masks show only the minimum needed: phone/card last four, IBAN tail, wallet prefix/suffix, gift-code suffix only when required. Masking never replaces authorization.
- Crypto values use fixed precision; fiat is integer IRR. Every exported amount includes currency/unit.

## Access and data flow rules

1. Repositories enforce customer ownership and administrator permission scope; presentation filtering is not an access control.
2. Reports and exports default to aggregation/masking. Export creation, download/send, and expiry are audited.
3. Outbox, queue, cache, alert, exception, and audit payloads carry internal identifiers, not Restricted values. If a restricted value is unavoidable, store a reference and resolve it only in the authorized worker.
4. Provider requests receive only contract-required fields. Provider responses are normalized immediately and discarded unless evidence retention is necessary.
5. Private media and backups live outside `public/`. Access is an authorized Telegram resend or controlled stream; storage paths are never exposed.
6. Production data is prohibited in fixtures, local developer databases, screenshots, issues, test evidence, or CI artifacts.
7. Non-production providers use distinct credentials and data. Test credentials are revoked before production.

## Provisional retention schedule

The product specification mandates configurable retention but does not establish jurisdiction-specific legal periods. The following values are conservative engineering defaults, not legal advice.

| Data category | Provisional active retention | Disposal / exception |
|---|---|---|
| OTP plaintext | Never persisted | Transient delivery only |
| OTP challenge/hash | 24 hours after expiry | Keep aggregate abuse counters up to 90 days |
| Web session/setup token/callback token | Until expiry + 24 hours diagnostic window | Delete securely; keep non-sensitive audit outcome |
| Raw Telegram update | Do not store by default | Store normalized required fields; exceptional encrypted capture max 7 days |
| Raw provider webhook/API payload | Do not retain by default; encrypted max 30 days when dispute/reconciliation requires | Keep canonical hash, normalized evidence, status and IDs longer |
| Receipt and gift-card image/code | Until review/capture plus 180 days provisional | Earlier deletion after dispute window if legally/business-approved; preserve redacted decision evidence |
| Ticket/direct-message attachments | Ticket closure + 180 days provisional | Legal hold or active dispute overrides |
| Application logs | 30 days online provisional | Security logs up to 90 days; restricted fields prohibited regardless |
| IP/rate-limit evidence | 90 days provisional | Retain only keyed/truncated data when sufficient |
| Conversation/session state | Expiry + 7 days provisional | Preserve resulting order/payment IDs, not stale payload |
| Exports | 24 hours by default | Delete artifact; retain audit metadata |
| Financial ledger, payment/refund, provider consumption, order snapshots | Long-term per approved accounting/legal policy; no normal hard delete | Append correction/reversal; legal hold supported |
| Administrator audit and security incidents | Long-term, minimum policy to be approved | Append-only; redacted of secrets |
| Service delivery secrets | While service is active and operational recovery requires; short grace after retirement | Retain non-secret service/audit metadata |
| Backups | Daily 7, weekly 4, monthly 6, plus release snapshots | Encrypted deletion; legal hold/incident snapshot exception |

No cleanup job may hard-delete ledger entries, payment consumption evidence, refund history, finalized order price snapshots, or audit entries. “Delete account” workflows must be designed after legal policy: detach/anonymize nonessential profile data while retaining required integrity records under a pseudonymous internal ID.

## Backup and deletion implications

- Deletion from the live database does not instantly erase encrypted backups. The retention notice and operational procedure must disclose the backup expiry window.
- Restoring an older backup must re-run tombstone/retention jobs before normal access where required.
- Legal hold is explicit, reasoned, authorized, time-bounded/reviewed, and audited.
- Encryption-key destruction is not used as an undocumented substitute for record-level retention because keys may protect multiple subjects/records.

## Owner decisions required before production

- Applicable jurisdiction and accounting, consumer dispute, identity, telecom, and privacy retention duties.
- Whether full sender card, national ID, receipt, or gift code is genuinely required for each enabled workflow.
- Final retention periods, data-subject request process, legal hold authority, and breach notification process.
- Which administrator roles may reveal Restricted fields and whether each reveal requires second approval.
- Backup and signing key custody/recovery personnel.

Until decided, optional Restricted fields and raw payload retention remain disabled by default; a provider or policy that requires them cannot be activated silently.
