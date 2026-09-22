<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use Illuminate\Database\Connection;
use RuntimeException;

final class PurchaseProviderMutationAttempt
{
    private bool $externalEffectStarted = false;

    private bool $reconciliationRequired = false;

    public function __construct(
        private readonly ?Connection $controlConnection,
        private readonly int $attemptId,
        private readonly int $providerSessionId,
        private readonly string $lockName,
    ) {}

    public static function noOp(): self
    {
        return new self(null, 0, 0, '');
    }

    public function id(): ?int
    {
        return $this->controlConnection === null ? null : $this->attemptId;
    }

    public function markExternalEffectStarted(): void
    {
        if ($this->controlConnection === null) {
            if ($this->externalEffectStarted) {
                throw new RuntimeException('Provider mutation attempt external effect was already marked as started.');
            }

            $this->externalEffectStarted = true;

            return;
        }
        if ($this->externalEffectStarted) {
            throw new RuntimeException('Provider mutation attempt external effect was already marked as started.');
        }

        $owner = $this->controlConnection->selectOne(
            'SELECT IS_USED_LOCK(?) AS owner_connection_id',
            [$this->lockName],
            false,
        );
        if ($owner === null || (int) ($owner->owner_connection_id ?? 0) !== $this->providerSessionId) {
            throw new RuntimeException('Provider mutation attempt lost its barrier slot before the external effect started.');
        }

        $updated = $this->controlConnection->update(
            <<<'SQL'
UPDATE purchase_provider_mutation_attempts
SET state = 'external_started',
    external_started_at = UTC_TIMESTAMP(6),
    updated_at = UTC_TIMESTAMP(6)
WHERE id = ?
  AND provider_session_id = ?
  AND state = 'prepared'
SQL,
            [$this->attemptId, $this->providerSessionId],
        );
        if ($updated !== 1) {
            throw new RuntimeException('Provider mutation attempt could not durably mark the external effect boundary.');
        }

        $this->externalEffectStarted = true;
    }

    public function requireReconciliation(): void
    {
        if ($this->controlConnection === null) {
            $this->reconciliationRequired = true;

            return;
        }
        if (! $this->externalEffectStarted) {
            throw new RuntimeException('Provider mutation reconciliation cannot be required before the external effect starts.');
        }
        if ($this->reconciliationRequired) {
            return;
        }

        $this->transition(
            'external_started',
            'reconciliation_required',
            false,
            'Provider mutation attempt could not durably record required reconciliation.',
        );
        $this->reconciliationRequired = true;
    }

    public function complete(): void
    {
        if ($this->controlConnection === null || $this->reconciliationRequired) {
            return;
        }

        if ($this->externalEffectStarted) {
            $this->transition(
                'external_started',
                'completed',
                true,
                'Provider mutation attempt could not durably record completed local convergence.',
            );
        } else {
            $this->transition(
                'prepared',
                'aborted',
                true,
                'Provider mutation attempt could not durably retire a no-effect preparation.',
            );
        }

        // Resolved rows are no longer migration authority. Their durable state is
        // committed before deletion, so a deletion failure is availability-only.
        $this->controlConnection->delete(
            "DELETE FROM purchase_provider_mutation_attempts WHERE id = ? AND state IN ('completed','aborted')",
            [$this->attemptId],
        );
    }

    public function fail(): void
    {
        if ($this->controlConnection === null || $this->reconciliationRequired) {
            return;
        }

        if (! $this->externalEffectStarted) {
            $this->transition(
                'prepared',
                'aborted',
                true,
                'Provider mutation attempt could not durably retire a failed pre-effect preparation.',
            );
            $this->controlConnection->delete(
                "DELETE FROM purchase_provider_mutation_attempts WHERE id = ? AND state = 'aborted'",
                [$this->attemptId],
            );

            return;
        }

        $this->transition(
            'external_started',
            'reconciliation_required',
            false,
            'Provider mutation attempt could not durably record required reconciliation.',
        );
    }

    private function transition(
        string $from,
        string $to,
        bool $resolved,
        string $failureMessage,
    ): void {
        $resolvedSql = $resolved ? 'UTC_TIMESTAMP(6)' : 'NULL';
        $updated = $this->controlConnection?->update(
            "UPDATE purchase_provider_mutation_attempts
             SET state = ?, resolved_at = {$resolvedSql}, updated_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND state = ?",
            [$to, $this->attemptId, $from],
        );
        if ($updated !== 1) {
            throw new RuntimeException($failureMessage);
        }
    }
}
