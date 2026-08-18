<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DeliveryAttempt object{id:int|string,public_id:string,service_subscription_id:int|string,correlation_id:string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string}
 * @phpstan-type DeliveryService object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type TelegramAccount object{id:int|string,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,is_bot:int|string|bool}
 * @phpstan-type DeliveryEffect object{id:int|string,public_id:string,service_delivery_attempt_id:int|string,service_subscription_id:int|string,telegram_account_id:int|string,telegram_bot_id:int|string,telegram_user_id:int|string,state:string,state_version:int|string,provider_boundary_started_at:?string,completed_at:?string,telegram_message_id:int|string|null,result_code:?string,retry_after_seconds:int|string|null}
 * @phpstan-type DeliveryContext array{attempt:DeliveryAttempt,service:DeliveryService,account:TelegramAccount,effect:DeliveryEffect}
 */
final readonly class ServiceDeliveryEffectExecutor
{
    use ServiceDeliveryEffectAuthority;
    use ServiceDeliveryEffectFinalization;
    use ServiceDeliveryEffectPersistence;

    private const EFFECT_AUTHORITY = 'service_delivery_effect_v1';

    /** @var list<string> */
    private const TERMINAL_MUTATION_STATES = ['succeeded', 'failed_final', 'compensated'];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private ProvisioningPanelAdapterResolver $adapters,
        private ProtectedTelegramDeliveryRuntime $telegram,
        private ProtectedTelegramMessageSender $sender,
    ) {}

    /** @requirement SVC-002 SVC-014 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function execute(string $attemptPublicId): ServiceDeliveryExecutionReceipt
    {
        $this->assertUlid($attemptPublicId);

        $recoveredState = $this->recover($attemptPublicId);
        if ($recoveredState !== null && $this->isTerminal($recoveredState)) {
            return $this->receiptByAttemptPublicId($attemptPublicId, true);
        }

        $context = $this->prepare($attemptPublicId);
        $state = $this->effectState($context['effect']->state);
        if ($this->isTerminal($state)) {
            return $this->receipt($context['attempt'], $context['effect'], true);
        }
        if ($state !== ServiceDeliveryEffectState::Prepared) {
            throw new DomainException('Service delivery effect is not safely executable.');
        }

        try {
            $targetId = $this->positiveDatabaseInt($context['service']->service_target_id, 'Service target ID');
            $remoteServiceId = $this->requiredString($context['service']->remote_service_id, 'Remote Service ID');
            $adapter = $this->adapters->resolve($targetId);
            $artifacts = $adapter->getDeliveryArtifacts($remoteServiceId);
        } catch (Throwable) {
            // No Telegram provider boundary has been crossed. Common Outbox retry is safe.
            return $this->receipt($context['attempt'], $context['effect'], false);
        }

        $text = $this->protectedText($artifacts);
        if ($text === null) {
            return $this->finalizePreparedFailure(
                $context['effect'],
                'delivery_text_unavailable',
            );
        }
        if (mb_strlen($text) > 4096) {
            return $this->finalizePreparedFailure(
                $context['effect'],
                'delivery_text_too_large',
            );
        }

        try {
            $sending = $this->enterProviderBoundary($context['effect']);
        } catch (DomainException) {
            return $this->finalizePreparedFailure(
                $context['effect'],
                'delivery_authority_stale',
            );
        }

        try {
            $result = $this->sender->send(
                $this->positiveDatabaseInt($sending->telegram_user_id, 'Telegram user ID'),
                $text,
            );
        } catch (Throwable) {
            return $this->finalizeBoundaryResult(
                $sending,
                new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::UncertainResult,
                    'telegram_sender_exception',
                ),
            );
        }

        return $this->finalizeBoundaryResult($sending, $result);
    }

    /**
     * Recover one interrupted provider boundary. A `sending` row is sufficient
     * evidence that a Telegram request may already have crossed the network.
     */
    public function recover(string $attemptPublicId): ?ServiceDeliveryEffectState
    {
        $this->assertUlid($attemptPublicId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($attemptPublicId): ?ServiceDeliveryEffectState {
            $attempt = $this->attemptByPublicId($connection, $attemptPublicId, false);
            $effect = $this->effectByAttemptId($connection, (int) $attempt->id, true);
            if ($effect === null) {
                return null;
            }

            $state = $this->effectState($effect->state);
            if ($state !== ServiceDeliveryEffectState::Sending) {
                return $state;
            }

            $now = $this->timestamp();
            $this->setEffectAuthority($connection, $effect);
            try {
                $updated = $connection->table('service_delivery_effects')
                    ->where('id', (int) $effect->id)
                    ->where('state', ServiceDeliveryEffectState::Sending->value)
                    ->where('state_version', (int) $effect->state_version)
                    ->update([
                        'state' => ServiceDeliveryEffectState::Uncertain->value,
                        'state_version' => (int) $effect->state_version + 1,
                        'completed_at' => $now,
                        'result_code' => 'interrupted_delivery_effect',
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Interrupted Service delivery recovery lost its effect state.');
                }
            } finally {
                $this->clearEffectAuthority($connection);
            }

            return ServiceDeliveryEffectState::Uncertain;
        }, 3);
    }

    /** @return DeliveryContext */
    private function prepare(string $attemptPublicId): array
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($attemptPublicId): array {
            // Delivery Attempt queue authority locks Service -> Attempt. Preserve that order here
            // so an Outbox execution cannot deadlock with a concurrent idempotent replay.
            $attemptLocator = $this->attemptByPublicId($connection, $attemptPublicId, false);
            $service = $this->serviceById($connection, (int) $attemptLocator->service_subscription_id, true);
            $attempt = $this->attemptById($connection, (int) $attemptLocator->id, true);
            if ((int) $attempt->service_subscription_id !== (int) $service->id) {
                throw new RuntimeException('Service Delivery Attempt changed Service authority unexpectedly.');
            }

            $this->assertCurrentAuthority($connection, $attempt, $service);
            $account = $this->telegramAccount($connection, $service);

            $effect = $this->effectByAttemptId($connection, (int) $attempt->id, true);
            if ($effect !== null) {
                $this->assertEffectRecipient($effect, $account);

                return compact('attempt', 'service', 'account', 'effect');
            }

            $effectPublicId = (string) Str::ulid();
            $now = $this->timestamp();
            $this->setEffectAuthorityForInsert($connection, $attempt, $account, $effectPublicId);
            try {
                $effectId = (int) $connection->table('service_delivery_effects')->insertGetId([
                    'public_id' => $effectPublicId,
                    'service_delivery_attempt_id' => $this->positiveDatabaseInt($attempt->id, 'Delivery Attempt ID'),
                    'service_subscription_id' => $this->positiveDatabaseInt($service->id, 'Service Subscription ID'),
                    'telegram_account_id' => $this->positiveDatabaseInt($account->id, 'Telegram account ID'),
                    'telegram_bot_id' => $this->positiveDatabaseInt($account->bot_id, 'Telegram bot ID'),
                    'telegram_user_id' => $this->positiveDatabaseInt($account->telegram_user_id, 'Telegram user ID'),
                    'state' => ServiceDeliveryEffectState::Prepared->value,
                    'state_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } finally {
                $this->clearEffectAuthority($connection);
            }

            $effect = $this->effectById($connection, $effectId, true);

            return compact('attempt', 'service', 'account', 'effect');
        }, 3);
    }

    /**
     * @param  DeliveryEffect  $locator
     * @return DeliveryEffect
     */
    private function enterProviderBoundary(object $locator): object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator): object {
            // Resolve immutable identities without locks, then acquire Service -> Attempt -> Effect.
            // This matches queue authority and prevents service/attempt lock-order inversion.
            $effectLocator = $this->effectById($connection, (int) $locator->id, false);
            $attemptLocator = $this->attemptById($connection, (int) $effectLocator->service_delivery_attempt_id, false);
            $service = $this->serviceById($connection, (int) $attemptLocator->service_subscription_id, true);
            $attempt = $this->attemptById($connection, (int) $attemptLocator->id, true);
            $effect = $this->effectById($connection, (int) $effectLocator->id, true);
            if ((int) $attempt->service_subscription_id !== (int) $service->id
                || (int) $effect->service_delivery_attempt_id !== (int) $attempt->id
                || (int) $effect->service_subscription_id !== (int) $service->id) {
                throw new RuntimeException('Service delivery provider boundary authority linkage changed unexpectedly.');
            }
            if ($this->effectState($effect->state) !== ServiceDeliveryEffectState::Prepared) {
                throw new DomainException('Service delivery provider boundary cannot be entered from the current state.');
            }

            $this->assertCurrentAuthority($connection, $attempt, $service);
            $account = $this->telegramAccount($connection, $service);
            $this->assertEffectRecipient($effect, $account);

            $now = $this->timestamp();
            $this->setEffectAuthority($connection, $effect);
            try {
                $updated = $connection->table('service_delivery_effects')
                    ->where('id', (int) $effect->id)
                    ->where('state', ServiceDeliveryEffectState::Prepared->value)
                    ->where('state_version', (int) $effect->state_version)
                    ->whereNull('provider_boundary_started_at')
                    ->update([
                        'state' => ServiceDeliveryEffectState::Sending->value,
                        'state_version' => (int) $effect->state_version + 1,
                        'provider_boundary_started_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Service delivery provider boundary lost its prepared authority.');
                }
            } finally {
                $this->clearEffectAuthority($connection);
            }

            return $this->effectById($connection, (int) $effect->id, true);
        }, 3);
    }
}
