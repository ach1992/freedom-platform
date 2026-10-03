# Freedom Platform 1.0.0

Final Version 1 release source prepared for Phase 1.0 release acceptance.

Building or validating this package does not itself authorize GitHub Release publication, production deployment, traffic activation, or protected live-provider mutation. Those remain explicit Phase 1.0 gates.

## Upgrade boundary

- Predecessor release: `0.9.0-rc.2`
- Predecessor application version: `0.9.0-rc.2`
- Target application version: `1.0.0`
- Predecessor and target migration identity: `fd2627b917f53dd848d88012d2b48d1508c3731c82e853e8b219544973612a6e`
- No migration changed between the accepted frozen RC source and this release-source preparation boundary.
- `0.9.0-rc.1` remains superseded rehearsal history; it was never published as a Git tag/Release and its pre-update backup path could not satisfy the reviewed least-privilege MariaDB contract. RC.2 carries the integrated backup fix without privilege expansion.
- Packaged runtime version identity is bound to the verified release manifest so RC.2 → 1.0.0 activation and compatible rollback cannot retain a stale shared `APP_VERSION`.

The final production package remains subject to final-candidate rebuild and validation after all Phase 1.0 source/documentation changes have converged.

## Version 1 scope

Freedom Platform 1.0.0 provides the Telegram-first Version 1 product boundary defined by the canonical specification, including:

- customer, agent/reseller, and administrator journeys;
- catalog, offering, panel-target, provisioning, renewal, add-on, trial, and service lifecycle controls;
- wallet, card-to-card, gift-card, USDT, Zarinpal, and NOWPayments payment authorities;
- transaction-safe/idempotent financial, provisioning, provider, webhook, and queue processing;
- Persian-first localized Telegram UX with English fallback;
- support, content, membership, broadcast, reporting, Operations Center, alerting, backup/restore, update/rollback, and recovery tooling;
- Marzban/PasarGuard adapter contracts with protected live compatibility acceptance remaining a release gate.

## Package trust and compatibility

The update package uses the Version 1 `freedom_platform_release_v1` contract.

Before any archive parsing, `PharUpdatePackageVerifier` requires the complete package SHA-256 supplied through the trusted release channel. It then verifies the manifest, exact payload checksum list, Composer lock identity, migration/schema identity, runtime requirements, rollback compatibility, archive shape, and extracted payload.

This is the specification's permitted trusted-checksum mechanism; no package is trusted from its embedded metadata alone.

## Validation boundary

The final `1.0.0` artifact must be rebuilt from the exact immutable final `main` candidate after Phase 1.0 source/documentation work converges and must pass the complete applicable release checks, target-like rehearsal, and protected provider acceptance required by Phase #13.

## Deployment

Production publication/deployment and post-deployment acceptance require explicit Owner approval after the final candidate and release evidence are complete.
