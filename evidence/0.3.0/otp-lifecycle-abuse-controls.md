# OTP lifecycle and abuse-control evidence

Requirements: `ONB-004`, `ONB-005`, `USR-001`, `SEC-003`, `DAT-003`, `INT-002`, `QUA-001`, `QUA-011`

## Verified application baseline

- verified application SHA: `82363cda52c9df2ede7c548faae9f0c55a215b3c`;
- self-hosted CI run: `30961861886`;
- complete MariaDB/authenticated Redis suite: **135 tests, 662 assertions, zero failures/errors/skips**;
- test artifact: `8913247224`, SHA-256 `b3bc14e01c74fe1076220ae72340fc1cf0abb42b848509e336319b7a278e9573`;
- static artifact: `8913263629`, SHA-256 `78f24495730dcfee9bb37bb1235e763ad8d792482540e38b497f5193e2e91c51`;
- dependency artifact: `8913252312`, SHA-256 `5003014313e500186295d95a3a5256f7a42dbf265d81c503d12d24a9a396c239`;
- secret-scan artifact: `8913235944`, SHA-256 `c42a080a9fa855dd829d889b680754c56a3548b4d3b0e4c4c40daf2292202d50`;
- preflight artifact: `8913230443`, SHA-256 `f6bc4104b394b66749f0281c503a6bb3142f9ea18a8d4f2373403e3d7ddee335`.

## Implemented controls

- six-digit OTP generated through the injected random-generator contract;
- OTP values stored only as versioned HMAC-SHA-256 hashes bound to the challenge ID;
- default validity window: two minutes;
- default resend cooldown: sixty seconds;
- maximum five verification attempts per challenge;
- wrong-attempt and expiration mutations commit before domain exceptions are returned;
- the fifth wrong attempt invalidates the challenge and releases its active-scope slot;
- successful verification consumes the challenge and invalidates sibling active challenges for the same number;
- issue requests are idempotent and do not send a second SMS for the same request key;
- one active challenge per user/number/purpose scope is enforced by a nullable unique keyed hash;
- request IP and idempotency material are stored only as keyed hashes;
- phone, Telegram-account and IP daily limits are consumed atomically in one Redis Lua operation;
- per-provider daily limits are enforced before sending;
- provider quota exhaustion is a definitive pre-send failure and may safely use the configured fallback;
- uncertain delivery outcomes never trigger fallback;
- OTP success creates or refreshes `sms_otp` verification evidence and re-evaluates contact-only, OTP-only, either and both policies;
- all issue, failed-attempt, lockout, expiry, verification, number-release and policy transitions retain append-only verification events.

## Automated scenarios

The retained suite verifies:

- idempotent issue and one provider send;
- hash-at-rest and no plaintext OTP persistence;
- failed-attempt persistence despite the returned exception;
- successful consumption and replay rejection;
- cooldown rejection and post-cooldown invalidation/reissue;
- five-attempt lockout;
- atomic multi-bucket Redis consumption;
- provider quota behavior and safe fallback;
- migration/schema compatibility on MariaDB;
- dependency injection and static architecture boundaries.

## Target staging rehearsal

Staging run `30962042217` deployed application SHA `82363cda52c9df2ede7c548faae9f0c55a215b3c` and passed the complete target rehearsal.

Artifact:

- ID `8913351595`;
- SHA-256 `25fabccb4bef90b20259ac649072120546ae1e3cb857da952fc1c4112aec44e2`.

Verified target facts:

- migration `2026_08_04_000700_extend_otp_challenge_lifecycle` applied;
- PHP CLI/LSPHP `8.4.23`, MariaDB `10.6.23`, Redis `7.4.2`;
- immutable Release A/B activation, explicit rollback and reactivation passed;
- active release `staging-b-82363cda52c9`;
- five Supervisor workers and five distinct fresh heartbeats;
- one Scheduler heartbeat and exactly one Cron entry;
- controlled stale-worker alert and recovery to zero unresolved alerts;
- redacted critical health result `healthy`;
- OTP issue idempotency passed with one provider send;
- hash-at-rest passed;
- wrong-attempt mutation committed and left four attempts;
- successful verification consumed the challenge and satisfied the OTP-only policy;
- Redis rate limit blocked the second atomic consume;
- synthetic database rows were rolled back and temporary Redis keys were removed;
- public HTTPS ready endpoint remained healthy.

## Gate conclusion

The OTP lifecycle and abuse-control package is passed on CI and target staging. Real SMS-provider adapters remain disabled; the next package adds Melli Payamak and Kavenegar HTTP adapters behind the existing `SmsProvider` contract with fake HTTP contract tests and no real credentials.
