# SMS provider contract note — 2026-08-05

Status: fake-tested adapter contract; real providers disabled by default.

Requirements: `ONB-004`, `SEC-003`, `INT-002`, `QUA-001`.

## Shared delivery contract

Both adapters implement `SmsProvider` and receive a validated `SmsOtpMessage`. The OTP body is rendered from version-controlled localization resources. The six-digit code, full destination, credentials, and idempotency key are never written to provider-result errors or debug output.

The delivery taxonomy is intentionally strict:

- `accepted`: the provider returned an authoritative message identifier;
- `definitive_failure`: the provider explicitly rejected the request before acceptance, so the configured secondary provider may be attempted;
- `uncertain`: timeout, transport failure, malformed success response, provider `5xx`, or another outcome where the request may have been accepted; fallback is forbidden to prevent duplicate SMS delivery.

No automatic HTTP retry is performed for a send request. Application-level rate limiting remains outside the adapters and is applied through `RateLimitedSmsProvider`.

## Kavenegar

Official reference checked: `https://kavenegar.com/rest.html` on 2026-08-05.

- HTTPS endpoint: `POST https://api.kavenegar.com/v1/{API-KEY}/sms/send.json`
- Authentication: API key in the provider-defined URL path.
- Request: form-encoded `receptor`, `message`, optional `sender`, and deterministic `localid`.
- Success: JSON `return.status = 200` plus a positive `entries[0].messageid`.
- Explicit provider/API rejection: definitive failure.
- HTTP/provider `409`, `5xx`, transport failure, or malformed success body: uncertain.
- HTTP `429`: definitive rate-limit rejection, allowing controlled secondary-provider fallback.
- Provider-side idempotency: deterministic `localid` derived from the application idempotency key.

## Melli Payamak

Official reference checked: `https://www.melipayamak.com/api/sendsimplesms2/` on 2026-08-05. The page reports an update date of 18 Aban 1404 and documents `SendSimpleSMS2` plus the REST endpoint used below.

- HTTPS endpoint: `POST https://rest.payamak-panel.com/api/SendSMS/SendSMS`
- Authentication: form-encoded username and password/API key.
- Request: form-encoded `username`, `password`, `to`, `from`, `text`, `isflash=false`.
- Success: a positive numeric provider receipt identifier, accepted as plain text or a documented-compatible JSON wrapper.
- Numeric provider error values and non-transient `4xx`: definitive failure.
- HTTP `408`, `409`, `425`, `5xx`, transport failure, or malformed success body: uncertain.
- HTTP `429`: definitive rate-limit rejection.
- Provider-side idempotency: unavailable in the documented simple-send REST method; local issue idempotency and no-retry/uncertain-result rules remain mandatory.

The official sample disables certificate checks; this implementation deliberately does not copy that behavior. Laravel's normal TLS certificate and hostname verification remains enabled and cannot be disabled through configuration.

## Configuration and activation

The default runtime lemains:

```text
SMS_PRIMARY_PROVIDER=fake_primary
SMS_FALLBACK_PROVIDER=fake_fallback
MELLI_PAYAMAK_ENABLED=false
KAVENEGAR_ENABLED=false
```

Selecting a missing, unsupported, or disabled real provider fails closed during dependency resolution. Credentials are read only from protected environment configuration and are not included in source, tests, issues, evidence, or logs.

## Fake contract coverage

`tests/Unit/Modules/Identity/SmsProviderHttpContractTest.php` covers localized rendering, accepted responses, explicit rejection, rate limiting, malformed responses, provider unavailability, transport failure, credential redaction, deterministic Kavenegar `localid`, definitive-failure fallback, and uncertain-result no-fallback behavior.

A controlled real-provider acceptance test remains a just-in-time activation gate and must not be run until test credentials and a destination number are supplied securely.
