# Freedom Platform 0.9.0-rc.1

Release candidate for Phase 0.9.0. This artifact is for release validation and packaging; it does not authorize production deployment or public activation.

## Upgrade boundary

- Predecessor source identity: `a099c9866ddc9240333c942a415d1a59cc6a608f`
- Predecessor application version: `0.8.0`
- Target application version: `0.9.0-rc.1`
- Predecessor and target migration identity: `fd2627b917f53dd848d88012d2b48d1508c3731c82e853e8b219544973612a6e`
- No migration changed between the accepted Phase 0.8 baseline and this RC source boundary.

## Phase 0.9 highlights

- Hardened Telegram outbound redirect handling and emergency-log redaction.
- Bounded Agent Pricing historical-version selection and Promotion reservation-capacity queries.
- Reconciled runtime configuration and CI contracts, including fail-on-risky PHPUnit behavior and truthful Feature sharding.
- Restored explicit Toman presentation for user-facing Iranian-fiat amounts while preserving canonical IRR storage/input/payment authority.
- Hardened FULL-suite timing evidence and added explicit chaos/recovery coverage for Redis restart, killed-worker recovery, interrupted backup export, and reordered bank observations.
- Completed release-level requirement, security/dependency, regression, performance-baseline, chaos/recovery, and target-like rehearsal reconciliation for Phase 0.9.

## Validation boundary

The release package must be built from one frozen exact commit and must pass the repository-owned `PharUpdatePackageVerifier` using its complete-package SHA-256 before any update preflight.

Performance evidence for this RC is a baseline only. It is not a production capacity certification because Owner-approved capacity targets have not been defined.

## Deployment

Production deployment, live provider/customer/financial mutation, public cutover, and destructive restore/update remain separately gated and are not authorized by this RC.
