<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DeliveryAttempt object{id:int|string,public_id:string,service_subscription_id:int|string,purpose:string,correlation_id:string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string}
 * @phpstan-type DeliveryService object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type TelegramAccount object{id:int|string,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,is_bot:int|string|bool}
 * @phpstan-type DeliveryEffect object{id:int|string,public_id:string,service_delivery_attempt_id:int|string,service_subscription_id:int|string,telegram_account_id:int|string,telegram_bot_id:int|string,telegram_user_id:int|string,state:string,state_version:int|string,provider_boundary_started_at:?string,completed_at:?string,telegram_message_id:int|string|null,result_code:?string,retry_after_seconds:int|string|null}
 */
trait ServiceDeliveryEffectPersistence
{
    private function receiptByAttemptPublicId(string $attemptPublicId, bool $replayed): ServiceDeliveryExecutionReceipt
    {
        $connection = $this->database->connection();
        $attempt = $this->attemptByPublicId($connection, $attemptPublicId, false);
        $effect = $this->effectByAttemptId($connection, (int) $attempt->id, false);
        if ($effect === null) {
            throw new RuntimeException('Service delivery effect evidence is unavailable.');
        }

        return $this->receipt($attempt, $effect, $replayed);
    }

    /**
     * @param  DeliveryAttempt  $attempt
     * @param  DeliveryEffect  $effect
     */
    private function receipt(object $attempt, object $effect, bool $replayed): ServiceDeliveryExecutionReceipt
    {
        return new ServiceDeliveryExecutionReceipt(
            (string) $attempt->public_id,
            $this->effectState($effect->state),
            $effect->telegram_message_id === null ? null : $this->positiveDatabaseInt($effect->telegram_message_id, 'Telegram message ID'),
            $effect->result_code,
            $effect->retry_after_seconds === null ? null : $this->positiveDatabaseInt($effect->retry_after_seconds, 'Telegram retry delay'),
            $replayed,
        );
    }

    /** @return DeliveryAttempt */
    private function attemptByPublicId(Connection $connection, string $publicId, bool $lock): object
    {
        $query = $connection->table('service_delivery_attempts')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DeliveryAttempt|null $row */
        $row = $query->first($this->attemptColumns());
        if ($row === null) {
            throw new DomainException('Service Delivery Attempt does not exist.');
        }

        return $row;
    }

    /** @return DeliveryAttempt */
    private function attemptById(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('service_delivery_attempts')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DeliveryAttempt|null $row */
        $row = $query->first($this->attemptColumns());
        if ($row === null) {
            throw new RuntimeException('Service Delivery Attempt disappeared during effect execution.');
        }

        return $row;
    }

    /** @return DeliveryService */
    private function serviceById(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('service_subscriptions')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DeliveryService|null $row */
        $row = $query->first([
            'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
            'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'remote_deleted_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Service Subscription disappeared during delivery execution.');
        }

        return $row;
    }

    /** @return DeliveryEffect|null */
    private function effectByAttemptId(Connection $connection, int $attemptId, bool $lock): ?object
    {
        $query = $connection->table('service_delivery_effects')->where('service_delivery_attempt_id', $attemptId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DeliveryEffect|null $row */
        $row = $query->first($this->effectColumns());

        return $row;
    }

    /** @return DeliveryEffect */
    private function effectById(Connection $connection, int $id, bool $lock): object
    {
        $query = $connection->table('service_delivery_effects')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DeliveryEffect|null $row */
        $row = $query->first($this->effectColumns());
        if ($row === null) {
            throw new RuntimeException('Service delivery effect evidence disappeared.');
        }

        return $row;
    }

    /** @return list<string> */
    private function attemptColumns(): array
    {
        return [
            'id', 'public_id', 'service_subscription_id', 'purpose', 'correlation_id',
            'target_remote_identity_generation', 'target_lifecycle_version',
        ];
    }

    /** @return list<string> */
    private function effectColumns(): array
    {
        return [
            'id', 'public_id', 'service_delivery_attempt_id', 'service_subscription_id', 'telegram_account_id', 'telegram_bot_id',
            'telegram_user_id', 'state', 'state_version', 'provider_boundary_started_at', 'completed_at',
            'telegram_message_id', 'result_code', 'retry_after_seconds',
        ];
    }

    private function effectState(string $value): ServiceDeliveryEffectState
    {
        return ServiceDeliveryEffectState::tryFrom($value)
            ?? throw new RuntimeException('Stored Service delivery effect state is invalid.');
    }

    private function isTerminal(ServiceDeliveryEffectState $state): bool
    {
        return in_array($state, [
            ServiceDeliveryEffectState::Succeeded,
            ServiceDeliveryEffectState::Uncertain,
            ServiceDeliveryEffectState::FailedFinal,
        ], true);
    }

    /**
     * @param  DeliveryAttempt  $attempt
     * @param  TelegramAccount  $account
     */
    private function setEffectAuthorityForInsert(
        Connection $connection,
        object $attempt,
        object $account,
        string $effectPublicId,
    ): void {
        $connection->statement('SET @app_service_delivery_effect_authority = ?', [self::EFFECT_AUTHORITY]);
        $connection->statement('SET @app_service_delivery_effect_attempt_id = ?', [(int) $attempt->id]);
        $connection->statement('SET @app_service_delivery_effect_service_id = ?', [(int) $attempt->service_subscription_id]);
        $connection->statement('SET @app_service_delivery_effect_public_id = ?', [$effectPublicId]);
        $connection->statement('SET @app_service_delivery_effect_telegram_account_id = ?', [(int) $account->id]);
        $connection->statement('SET @app_service_delivery_effect_bot_id = ?', [(int) $account->bot_id]);
        $connection->statement('SET @app_service_delivery_effect_telegram_user_id = ?', [(int) $account->telegram_user_id]);
        if ($attempt->purpose === ServiceDeliveryPurpose::Notification->value) {
            (new ServiceOperationalDatabaseCapability)->apply($connection);
        }
    }

    /** @param DeliveryEffect $effect */
    private function setEffectAuthority(Connection $connection, object $effect): void
    {
        $purpose = $connection->table('service_delivery_attempts')
            ->where('id', (int) $effect->service_delivery_attempt_id)
            ->value('purpose');
        $connection->statement('SET @app_service_delivery_effect_authority = ?', [self::EFFECT_AUTHORITY]);
        $connection->statement('SET @app_service_delivery_effect_attempt_id = ?', [(int) $effect->service_delivery_attempt_id]);
        $connection->statement('SET @app_service_delivery_effect_service_id = ?', [(int) $effect->service_subscription_id]);
        $connection->statement('SET @app_service_delivery_effect_public_id = ?', [(string) $effect->public_id]);
        $connection->statement('SET @app_service_delivery_effect_telegram_account_id = ?', [(int) $effect->telegram_account_id]);
        $connection->statement('SET @app_service_delivery_effect_bot_id = ?', [(int) $effect->telegram_bot_id]);
        $connection->statement('SET @app_service_delivery_effect_telegram_user_id = ?', [(int) $effect->telegram_user_id]);
        if ($purpose === ServiceDeliveryPurpose::Notification->value) {
            (new ServiceOperationalDatabaseCapability)->apply($connection);
        }
    }

    private function clearEffectAuthority(Connection $connection): void
    {
        try {
            $this->clearEffectAuthoritySession($connection);
        } catch (Throwable $exception) {
            $this->disconnect($connection);
            throw $exception;
        }
    }

    private function clearEffectAuthoritySession(Connection $connection): void
    {
        $authority = $connection->selectOne('SELECT @app_service_delivery_effect_attempt_id AS attempt_id');
        $attemptId = $authority !== null && property_exists($authority, 'attempt_id')
            ? filter_var($authority->attempt_id, FILTER_VALIDATE_INT)
            : false;
        $notificationAuthority = $attemptId !== false
            && $attemptId > 0
            && $connection->table('service_delivery_attempts')
                ->where('id', $attemptId)
                ->value('purpose') === ServiceDeliveryPurpose::Notification->value;
        $connection->statement('SET @app_service_delivery_effect_authority = NULL');
        $connection->statement('SET @app_service_delivery_effect_attempt_id = NULL');
        $connection->statement('SET @app_service_delivery_effect_service_id = NULL');
        $connection->statement('SET @app_service_delivery_effect_public_id = NULL');
        $connection->statement('SET @app_service_delivery_effect_telegram_account_id = NULL');
        $connection->statement('SET @app_service_delivery_effect_bot_id = NULL');
        $connection->statement('SET @app_service_delivery_effect_telegram_user_id = NULL');
        if ($notificationAuthority) {
            (new ServiceOperationalDatabaseCapability)->clear($connection);
        }
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertUlid(string $value): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException('Service Delivery Attempt public ID is invalid.');
        }
    }

    private function requiredString(?string $value, string $label): string
    {
        if ($value === null || $value === '') {
            throw new RuntimeException($label.' is unavailable.');
        }

        return $value;
    }

    private function safeResultCode(string $value): string
    {
        return preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $value) === 1
            ? $value
            : 'delivery_result_unclassified';
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
