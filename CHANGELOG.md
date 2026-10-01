# Changelog

All notable project changes are recorded here. Versions follow Semantic Versioning.

## [Unreleased]

No release-scoped changes are currently queued beyond the Version 1 final-acceptance work.

## [1.0.0]

### Added

- Telegram-first customer, agent/reseller, administrator, support, content, broadcast, reporting, and operational workflows.
- Catalog, offering, panel-target, provisioning, renewal, add-on, trial, and service lifecycle management.
- Wallet, card-to-card, gift-card, USDT, Zarinpal, and NOWPayments payment flows with explicit financial authority.
- Marzban and PasarGuard panel adapter contracts, target/capability management, and protected service mutation boundaries.
- Encrypted backup/restore, atomic release update/rollback, health checks, Operations Center, alerting, and release packaging.
- Persian-first localization with English fallback and managed/versioned content and Telegram menu configuration.

### Changed

- Release packaging is reproducible and verifies an out-of-band trusted complete-package SHA-256 before manifest/payload processing.
- User-facing Iranian fiat presentation uses explicit Toman conversion while canonical storage/payment authority remains integer IRR.
- CI uses truthful Feature sharding, real MariaDB 10.11/authenticated Redis integration, release-level FULL coverage, and bounded chaos/recovery evidence.

### Security

- Default-deny execution-time authorization, replay/idempotency controls, SSRF/TLS/file/private-evidence boundaries, secret/log redaction, and dependency/license/secret/static release gates are enforced.
- External-effect uncertainty is persisted/reconciled instead of blindly retried.
- Backup, restore, updater, migration, and rollback paths fail closed on incompatible or ambiguous authority.
