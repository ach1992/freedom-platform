# Identity phone, Telegram contact, and SMS fallback foundation

Requirements: `ONB-004`, `ONB-005`, `USR-001`, `SEC-003`, `DAT-003`, `INT-002`, `QUA-001`, `QUA-011`

## Verified application baseline

- verified application SHA: `750bcdf994f864859d1a0a2c0e5fda476793bfc9`;
- self-hosted CI run: `30959686735`;
- complete MariaDB/authenticated Redis suite: **129 tests, 619 assertions, zero failures/errors/skips**;
- test artifact: `8912466408`, SHA-256 `c123dbbecae0c1810c47c3203740381827d7fdcbcdbbfb0cf6ddd9c573b48394`;
- static artifact: `8912452160`, SHA-256 `7cbc075bd001ade613965a92d9046854e881ef77d111aae6da034745e2dbaa0c`;
- dependency artifact: `8912457781`, SHA-256 `264fbeb15fae10a91d74a935d77d75edeabae39c9f651f56432b456db980303c`;
- secret scan artifact: `8912441205`, SHA-256 `9ca5389af074c58adcc871e4dcdc870d4ab9880ece87d34d422648989daae30c`.

## Implemented behavior

- Iranian mobile normalization accepts national, `98...`, and `+98...` forms;
- Persian and Arabic digits, spaces, dashes, and parentheses normalize to canonical E.164;
- canonical phone values are encrypted at rest;
- keyed HMAC-SHA-256 hashes support uniqueness and lookup without plaintext exposure;
- Telegram contact verification requires `contact.user_id` to equal the sender Telegram user ID and requires the sender/account/customer binding;
- one active mobile number is globally unique and one active number is allowed per user;
- replacing a number releases the previous active binding and invalidates its verification evidence;
- verification policies support none, contact-only, OTP-only, either, and both;
- a `both` policy remains pending after contact verification until OTP evidence exists;
- append-only phone verification events retain state, method, policy version, correlation, and release reason;
- SMS provider results distinguish accepted, definitive failure, and uncertain outcome;
- fallback is allowed only after definitive failure and is forbidden after uncertain timeout;
- fake providers retain only hashed request evidence;
- provider message IDs are encrypted and OTP/phone values are not persisted in delivery evidence.

## Target staging evidence

Staging deployment run `30959844808` applied migration `2026_08_04_000600_extend_phone_verification_foundation` and passed the complete deployment rehearsal:

- immutable Release A/B activation;
- explicit rollback and reactivation;
- five Supervisor workers and five fresh heartbeats;
- Scheduler heartbeat and exactly one Cron;
- controlled stale-worker alert and recovery;
- redacted critical health result `healthy`;
- active release `staging-b-750bcdf994f8`.

Deployment artifact: `8912555622`, SHA-256 `1907a806a563380641f3608558aaf5889860bdfa9c83af6545f8a4a4086614fc`.

The deployment workflow's original custom smoke step incorrectly invoked Ubuntu's system PHP 8.1 after the application deployment had already passed. The corrected smoke run `30960110251` used `/www/server/php/84/bin/php` and passed:

- PHP runtime `8.4.23`;
- migration present;
- Identity service-provider binding resolved;
- Persian-digit phone normalization returned the expected E.164 value;
- keyed lookup hash length/version valid;
- HTTPS ready endpoint healthy.

Smoke artifact: `8912596936`, SHA-256 `38e51e56fdbff57795fc4918f5b40f528171d6eb04f5e5b9ffbc89fe5391903f`.

## Gate conclusion

The phone/contact/SMS fallback foundation is passed. OTP issuance, verification, invalidation, cooldown, attempt limits, abuse controls, and real-provider contract adapters remain required before the Phase `0.3.0` gate can pass.
