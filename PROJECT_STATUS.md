# Project Status

Target release: `1.0.0`

Current integration:

- release branch: `main`
- development/integration branch: `develop/v1.0.0-completion`
- cumulative integration PR: Draft `#6`
- active product phase: `0.5.0 — Ledger, Pricing, Promotions and Payment Providers`
- authoritative phase Issue: `#8`

GitHub is authoritative for live task, Worker branch, PR, review, blocker, and CI state. This file intentionally does not copy SHAs, run IDs, test counts, artifact IDs, or active Worker inventories.

## Current phase boundary

Phase `0.5.0` owns remaining pricing, promotion/referral, payment-method routing, purchase-bound payment authority, card-to-card, gift-card, USDT, and internet-payment capabilities.

Accepted foundations from earlier work must be reused rather than recreated, including wallet/ledger primitives, Quote/pricing snapshots, promotions/benefit-code foundations, agent pricing, payment top-up primitives, and bounded USDT rate/amount-quote work.

Order state, provisioning orchestration, and Service lifecycle remain Phase `0.6.0` / Issue `#9`. The existing `wallet_top_up` flow is not purchase settlement and must never be used as authority for paid Order provisioning.

## Development state

For current work:

1. open Draft PR `#6` and use its live head;
2. open Issue `#8` for Phase 0.5 backlog/dependencies;
3. inspect the specific open task Issue/PR involved;
4. inspect exact-head CI before review or integration.

Do not create new status or handoff documents. GitHub Issues/PRs are the live project board.

## Release boundary

`main` remains unchanged until explicit Version 1 release acceptance. Draft PR `#6` must not be merged, marked Ready, auto-merged, or history-rewritten without explicit owner approval.