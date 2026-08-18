<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DomainException;
use Illuminate\Database\Connection;

/**
 * @phpstan-type DeliveryAttempt object{id:int|string,service_subscription_id:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string}
 * @phpstan-type DeliveryService object{id:int|string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type TelegramAccount object{id:int|string,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,is_bot:int|string|bool}
 * @phpstan-type DeliveryEffect object{id:int|string,telegram_account_id:int|string,telegram_bot_id:int|string,telegram_user_id:int|string}
 */
trait ServiceDeliveryEffectAuthority
{
    /**
     * @param  DeliveryAttempt  $attempt
     * @param  DeliveryService  $service
     */
    private function assertCurrentAuthority(Connection $connection, object $attempt, object $service): void
    {
        if ($service->remote_deleted_at !== null
            || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
            || $service->provisioned_at === null
            || $service->service_target_id === null
            || (int) $service->service_target_id < 1
            || ! is_string($service->remote_service_id)
            || $service->remote_service_id === ''
            || (int) $service->remote_identity_generation !== (int) $attempt->target_remote_identity_generation
            || (int) $service->lifecycle_version !== (int) $attempt->target_lifecycle_version) {
            throw new DomainException('Service delivery attempt is stale for the current Service authority.');
        }

        $activeMutation = $connection->table('provisioning_operations')
            ->where('service_subscription_id', (int) $service->id)
            ->where('operation_type', '<>', 'initial_provision')
            ->whereNotIn('state', self::TERMINAL_MUTATION_STATES)
            ->first(['id']);
        if ($activeMutation !== null) {
            throw new DomainException('Service delivery effect is blocked by an unresolved Service mutation.');
        }

        $blockingDelivery = $connection->table('service_delivery_effects')
            ->where('blocking_service_subscription_id', (int) $service->id)
            ->first(['id']);
        if ($blockingDelivery !== null) {
            throw new DomainException('Service delivery effect is blocked by an in-flight, uncertain, or provider-directed retry boundary.');
        }
    }

    /**
     * @param  DeliveryService  $service
     * @return TelegramAccount
     */
    private function telegramAccount(Connection $connection, object $service): object
    {
        $botId = $this->positiveDatabaseInt($this->telegram->botId, 'Telegram runtime bot ID');
        /** @var TelegramAccount|null $account */
        $account = $connection->table('telegram_accounts')
            ->where('user_id', (int) $service->user_id)
            ->where('bot_id', $botId)
            ->where('is_bot', false)
            ->first(['id', 'user_id', 'bot_id', 'telegram_user_id', 'is_bot']);
        if ($account === null || (int) $account->telegram_user_id < 1) {
            throw new DomainException('Service owner does not have a valid Telegram identity for the active runtime bot.');
        }

        return $account;
    }

    /**
     * @param  DeliveryEffect  $effect
     * @param  TelegramAccount  $account
     */
    private function assertEffectRecipient(object $effect, object $account): void
    {
        if ((int) $effect->telegram_account_id !== (int) $account->id
            || (int) $effect->telegram_bot_id !== (int) $account->bot_id
            || (int) $effect->telegram_user_id !== (int) $account->telegram_user_id) {
            throw new DomainException('Prepared Service delivery recipient no longer matches current Telegram authority.');
        }
    }

    private function protectedText(SensitiveDeliveryArtifacts $artifacts): ?string
    {
        $links = $artifacts->revealForAuthorizedDelivery();
        if ($links === []) {
            return null;
        }

        $parts = ['مشخصات سرویس شما'];
        foreach ($links as $index => $link) {
            $parts[] = count($links) === 1
                ? $link
                : 'لینک '.($index + 1).":\n".$link;
        }

        return implode("\n\n", $parts);
    }
}
