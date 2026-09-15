<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

/** @phpstan-type DeliveryEffect object{id:int|string,service_delivery_attempt_id:int|string,state:string,state_version:int|string} */
trait ServiceDeliveryEffectFinalization
{
    /** @param DeliveryEffect $locator */
    private function finalizePreparedFailure(object $locator, string $resultCode): ServiceDeliveryExecutionReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator, $resultCode): ServiceDeliveryExecutionReceipt {
            $effect = $this->effectById($connection, (int) $locator->id, true);
            $state = $this->effectState($effect->state);
            $attempt = $this->attemptById($connection, (int) $effect->service_delivery_attempt_id, false);
            if ($this->isTerminal($state)) {
                return $this->receipt($attempt, $effect, true);
            }
            if ($state !== ServiceDeliveryEffectState::Prepared) {
                throw new DomainException('Service delivery pre-boundary failure lost its prepared state.');
            }

            $now = $this->timestamp();
            $this->setEffectAuthority($connection, $effect);
            try {
                $updated = $connection->table('service_delivery_effects')
                    ->where('id', (int) $effect->id)
                    ->where('state', ServiceDeliveryEffectState::Prepared->value)
                    ->where('state_version', (int) $effect->state_version)
                    ->update([
                        'state' => ServiceDeliveryEffectState::FailedFinal->value,
                        'state_version' => (int) $effect->state_version + 1,
                        'completed_at' => $now,
                        'result_code' => $this->safeResultCode($resultCode),
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service delivery pre-boundary failure lost its effect state.');
                }
            } finally {
                $this->clearEffectAuthority($connection);
            }

            $next = $this->effectById($connection, (int) $effect->id, true);

            return $this->receipt($attempt, $next, false);
        }, 3);
    }

    /**
     * @param  DeliveryEffect  $locator
     */
    private function finalizeBoundaryResult(object $locator, ProtectedTelegramSendResult $result): ServiceDeliveryExecutionReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator, $result): ServiceDeliveryExecutionReceipt {
            $effect = $this->effectById($connection, (int) $locator->id, true);
            $attempt = $this->attemptById($connection, (int) $effect->service_delivery_attempt_id, false);
            $state = $this->effectState($effect->state);
            if ($this->isTerminal($state)) {
                return $this->receipt($attempt, $effect, true);
            }
            if ($state !== ServiceDeliveryEffectState::Sending) {
                throw new DomainException('Service delivery provider result lost its sending state.');
            }

            $nextState = match ($result->outcome) {
                ProtectedTelegramSendOutcome::Success => ServiceDeliveryEffectState::Succeeded,
                ProtectedTelegramSendOutcome::UncertainResult => ServiceDeliveryEffectState::Uncertain,
                ProtectedTelegramSendOutcome::DefinitiveFailure,
                ProtectedTelegramSendOutcome::RetryAfter => ServiceDeliveryEffectState::FailedFinal,
            };
            $now = $this->timestamp();
            $fields = [
                'state' => $nextState->value,
                'state_version' => (int) $effect->state_version + 1,
                'completed_at' => $now,
                'result_code' => $this->safeResultCode($result->resultCode),
                'updated_at' => $now,
            ];
            if ($result->outcome === ProtectedTelegramSendOutcome::Success) {
                $fields['telegram_message_id'] = $result->messageId;
            }
            if ($result->outcome === ProtectedTelegramSendOutcome::RetryAfter) {
                $fields['retry_after_seconds'] = $result->retryAfterSeconds;
            }

            $this->setEffectAuthority($connection, $effect);
            try {
                $updated = $connection->table('service_delivery_effects')
                    ->where('id', (int) $effect->id)
                    ->where('state', ServiceDeliveryEffectState::Sending->value)
                    ->where('state_version', (int) $effect->state_version)
                    ->update($fields);
                if ($updated !== 1) {
                    throw new RuntimeException('Service delivery provider result lost its effect state.');
                }
            } finally {
                $this->clearEffectAuthority($connection);
            }

            $next = $this->effectById($connection, (int) $effect->id, true);

            return $this->receipt($attempt, $next, false);
        }, 3);
    }
}
