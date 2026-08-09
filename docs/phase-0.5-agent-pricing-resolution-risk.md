# Phase 0.5 agent pricing resolution risk boundary

Issue `#28`, Contract Revision `1`, Worker `W-003`.

## Controlled risks

- **Ambiguous scope selection:** specificity is only the count of constrained supported dimensions. There is no hidden action/offering/server/product precedence. More than one active top-specificity match fails closed and persists nothing.
- **Stale or unauthorized subject:** new resolution requires the caller user to equal the subject, an active `agent` account, an active agent profile, and an exact current pricing-profile code.
- **Mutable historical pricing:** accepted resolutions snapshot profile/rule identity, version, hashes, integer IRR override, scope, formula version, and discount-combination policy. Exact replay reads the accepted immutable record before current-state authorization/config checks.
- **Configuration tampering:** profile/rule versions and accepted resolutions are append-only and hash-checked by service logic and MariaDB guards; malformed hashes, invalid state/amount, FK violations, updates, and deletes are rejected by focused tests.
- **Order-dependent rule choice:** all latest active matching rules are evaluated before selecting the maximum specificity. Row insertion/order does not choose the result.
- **Unexpected Promotions or money effects:** the resolver does not invoke Promotions, create ledger transactions, or create payment intents. Discount combination is snapshotted policy only.

## Residual integration risk

The Quote/BUY-002 flow does not yet consume this foundation. Therefore AGT-005 is complete only as the bounded agent-pricing resolution foundation owned by Issue #28, not as end-to-end checkout pricing. A later integration must preserve BUY-002's accepted quote snapshot/replay semantics, consume the stored resolver result exactly once, and apply Promotions only according to the snapshotted combination policy.

## Parallel-work boundary

W-003 did not modify `app/Modules/Promotions/**`, Promotions migrations, accepted Quote semantics, CI workflows/toolchain, `composer.lock`, or W-002-owned Promotions lifecycle. Integration conflicts should be resolved by preserving those ownership boundaries rather than rebasing or rewriting this worker branch.