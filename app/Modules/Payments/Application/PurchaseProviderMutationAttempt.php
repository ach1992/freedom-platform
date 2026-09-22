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

        $updated = $this->controlConnection->table('purchase_provider_mutation_attempts')
            ->where('id', $this->attemptId)
            ->where('provider_session_id', $this->providerSessionId)
            ->where('state', 'prepared')
            ->update([
                'state' => 'external_started',
                'external_started_at' => $this->controlConnection->raw('UTC_TIMESTAMP(6)'),
                'updated_at' => $this->controlConnection->raw('UTC_TIMESTAMP(6)'),
            ]);
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
        $this->controlConnection->table('purchase_provider_mutation_attempts')
            ->where('id', $this->attemptId)
            ->whereIn('state', ['completed', 'aborted'])
            ->delete();
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
            $this->controlConnection->table('purchase_provider_mutation_attempts')
                ->where('id', $this->attemptId)
                ->where('state', 'aborted')
                ->delete();

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
        $connection = $this->controlConnection
            ?? throw new RuntimeException('Provider mutation attempt control connection is unavailable.');
        $updated = $connection->table('purchase_provider_mutation_attempts')
            ->where('id', $this->attemptId)
            ->where('state', $from)
            ->update([
                'state' => $to,
                'resolved_at' => $resolved ? $connection->raw('UTC_TIMESTAMP(6)') : null,
                'updated_at' => $connection->raw('UTC_TIMESTAMP(6)'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException($failureMessage);
        }
    }
}
