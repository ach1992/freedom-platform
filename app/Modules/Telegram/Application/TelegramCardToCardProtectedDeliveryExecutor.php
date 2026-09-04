<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Domain\TelegramCardToCardProtectedDeliveryState;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class TelegramCardToCardProtectedDeliveryExecutor
{
    private const EFFECT_AUTHORITY = 'telegram_c2c_protected_effect_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private TelegramCustomerPurchaseCardToCardPayment $payments,
        private ProtectedTelegramMessageSender $sender,
        private Translator $translator,
    ) {}

    /** @requirement C2C-001 DAT-003 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function recover(
        string $publicId,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
    ): TelegramCardToCardProtectedDeliveryState {
        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId): TelegramCardToCardProtectedDeliveryState {
            $row = $this->delivery($connection, $publicId, true);
            $this->assertExpectedIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            $state = $this->state($row);
            if ($state !== TelegramCardToCardProtectedDeliveryState::Sending) {
                return $state;
            }

            return $this->state($this->transition(
                $connection,
                $row,
                TelegramCardToCardProtectedDeliveryState::Uncertain,
                'telegram_c2c_protected_boundary_interrupted',
                null,
                null,
                true,
            ));
        }, 3);
    }

    /** @requirement C2C-001 DAT-002 DAT-003 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function execute(
        string $publicId,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
    ): TelegramCardToCardProtectedDeliveryState {
        try {
            /** @var array{row:object,presentation:?ProtectedTelegramPresentation,boundary_entered:bool} $prepared */
            $prepared = $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId): array {
                $row = $this->delivery($connection, $publicId, true);
                $this->assertExpectedIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
                $state = $this->state($row);
                if ($state === TelegramCardToCardProtectedDeliveryState::Sending) {
                    $row = $this->transition(
                        $connection,
                        $row,
                        TelegramCardToCardProtectedDeliveryState::Uncertain,
                        'telegram_c2c_protected_boundary_reentry_uncertain',
                        null,
                        null,
                        true,
                    );

                    return ['row' => $row, 'presentation' => null, 'boundary_entered' => false];
                }
                if ($state !== TelegramCardToCardProtectedDeliveryState::Prepared) {
                    return ['row' => $row, 'presentation' => null, 'boundary_entered' => false];
                }

                $this->assertRecipient($connection, $row);
                $pan = $this->payments->destinationPanForSelf(
                    (int) $row->user_id,
                    (int) $row->user_id,
                    (string) $row->c2c_reservation_public_id,
                );
                $locale = $this->locale((string) $row->locale);
                $presentation = ProtectedTelegramPresentation::plainTextWithCopyText(
                    $this->translation('telegram.navigation.purchase.payment_methods.card_to_card_payment.copy_message', $locale),
                    $this->translation('telegram.navigation.purchase.payment_methods.card_to_card_payment.copy_button', $locale),
                    $pan,
                );
                $row = $this->enterProviderBoundary($connection, $row);

                return ['row' => $row, 'presentation' => $presentation, 'boundary_entered' => true];
            }, 3);
        } catch (AuthorizationException) {
            return $this->finalizePreparedFailure(
                $publicId,
                $expectedOutboxEventId,
                $expectedCorrelationId,
                'telegram_c2c_protected_authority_unavailable',
            );
        }

        $row = $prepared['row'];
        if (! $prepared['boundary_entered']) {
            return $this->state($row);
        }
        $presentation = $prepared['presentation']
            ?? throw new RuntimeException('Telegram card-to-card protected delivery presentation is unavailable.');

        try {
            $result = $this->sender->send((int) $row->telegram_user_id, $presentation);
        } catch (Throwable) {
            $result = new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_c2c_protected_sender_exception',
            );
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId, $result): TelegramCardToCardProtectedDeliveryState {
            $row = $this->delivery($connection, $publicId, true);
            $this->assertExpectedIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            if ($this->state($row) !== TelegramCardToCardProtectedDeliveryState::Sending) {
                return $this->state($row);
            }

            [$state, $completed] = match ($result->outcome) {
                ProtectedTelegramSendOutcome::Success => [TelegramCardToCardProtectedDeliveryState::Succeeded, true],
                ProtectedTelegramSendOutcome::DefinitiveFailure => [TelegramCardToCardProtectedDeliveryState::FailedFinal, true],
                ProtectedTelegramSendOutcome::RetryAfter => [TelegramCardToCardProtectedDeliveryState::ReviewRequired, true],
                ProtectedTelegramSendOutcome::UncertainResult => [TelegramCardToCardProtectedDeliveryState::Uncertain, true],
            };
            $updated = $this->transition(
                $connection,
                $row,
                $state,
                $result->resultCode,
                $result->messageId,
                $result->retryAfterSeconds,
                $completed,
            );

            return $this->state($updated);
        }, 3);
    }

    private function finalizePreparedFailure(
        string $publicId,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
        string $resultCode,
    ): TelegramCardToCardProtectedDeliveryState {
        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId, $resultCode): TelegramCardToCardProtectedDeliveryState {
            $row = $this->delivery($connection, $publicId, true);
            $this->assertExpectedIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            $state = $this->state($row);
            if ($state !== TelegramCardToCardProtectedDeliveryState::Prepared) {
                return $state;
            }

            return $this->state($this->transition(
                $connection,
                $row,
                TelegramCardToCardProtectedDeliveryState::FailedFinal,
                $resultCode,
                null,
                null,
                true,
            ));
        }, 3);
    }

    private function assertRecipient(Connection $connection, object $row): void
    {
        $account = $connection->table('telegram_accounts')
            ->where('id', (int) $row->telegram_account_id)
            ->lockForUpdate()
            ->first(['user_id', 'telegram_user_id', 'is_bot']);
        if ($account === null
            || (int) $account->user_id !== (int) $row->user_id
            || (int) $account->telegram_user_id !== (int) $row->telegram_user_id
            || (bool) $account->is_bot) {
            throw new AuthorizationException('Telegram card-to-card protected delivery recipient is unavailable.');
        }
    }

    private function enterProviderBoundary(Connection $connection, object $row): object
    {
        return $this->transition(
            $connection,
            $row,
            TelegramCardToCardProtectedDeliveryState::Sending,
            null,
            null,
            null,
            false,
        );
    }

    private function transition(
        Connection $connection,
        object $row,
        TelegramCardToCardProtectedDeliveryState $nextState,
        ?string $resultCode,
        ?int $messageId,
        ?int $retryAfterSeconds,
        bool $completed,
    ): object {
        $current = $this->state($row);
        $version = $this->positiveInt($row->state_version, 'Telegram card-to-card protected delivery state version');
        $attempts = $this->nonNegativeInt($row->provider_attempts, 'Telegram card-to-card protected delivery provider attempts');
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        $fields = [
            'state' => $nextState->value,
            'state_version' => $version + 1,
            'updated_at' => $timestamp,
        ];
        if ($nextState === TelegramCardToCardProtectedDeliveryState::Sending) {
            if ($current !== TelegramCardToCardProtectedDeliveryState::Prepared) {
                throw new RuntimeException('Telegram card-to-card protected provider boundary is not enterable.');
            }
            $fields += [
                'provider_attempts' => $attempts + 1,
                'provider_boundary_started_at' => $timestamp,
                'completed_at' => null,
                'telegram_message_id' => null,
                'result_code' => null,
                'retry_after_seconds' => null,
            ];
        } else {
            $safeResultCode = $this->safeResultCode($resultCode);
            $fields += [
                'completed_at' => $completed ? $timestamp : null,
                'telegram_message_id' => $nextState === TelegramCardToCardProtectedDeliveryState::Succeeded ? $messageId : null,
                'result_code' => $safeResultCode,
                'retry_after_seconds' => $nextState === TelegramCardToCardProtectedDeliveryState::ReviewRequired ? $retryAfterSeconds : null,
            ];
        }

        $this->setEffectAuthority($connection, (string) $row->public_id, $version);
        try {
            $updated = $connection->table('telegram_c2c_protected_deliveries')
                ->where('id', (int) $row->id)
                ->where('state_version', $version)
                ->where('state', $current->value)
                ->update($fields);
        } finally {
            $this->clearEffectAuthority($connection);
        }
        if ($updated !== 1) {
            throw new RuntimeException('Telegram card-to-card protected delivery lost effect authority.');
        }

        return $this->delivery($connection, (string) $row->public_id, false);
    }

    private function delivery(Connection $connection, string $publicId, bool $lock): object
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
            throw new AuthorizationException('Telegram card-to-card protected delivery is unavailable.');
        }
        $query = $connection->table('telegram_c2c_protected_deliveries')->where('public_id', strtoupper($publicId));
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new AuthorizationException('Telegram card-to-card protected delivery is unavailable.');
        }

        return $row;
    }

    private function assertExpectedIdentity(object $row, string $expectedOutboxEventId, string $expectedCorrelationId): void
    {
        if (! hash_equals((string) $row->outbox_event_id, $expectedOutboxEventId)
            || ! hash_equals((string) $row->correlation_id, $expectedCorrelationId)) {
            throw new AuthorizationException('Telegram card-to-card protected delivery Outbox authority is unavailable.');
        }
    }

    private function state(object $row): TelegramCardToCardProtectedDeliveryState
    {
        return TelegramCardToCardProtectedDeliveryState::tryFrom((string) $row->state)
            ?? throw new RuntimeException('Stored Telegram card-to-card protected delivery state is invalid.');
    }

    private function locale(string $locale): string
    {
        return in_array($locale, ['fa', 'en'], true) ? $locale : 'en';
    }

    private function translation(string $key, string $locale): string
    {
        $text = $this->translator->get($key, [], $locale);
        if (! is_string($text) || $text === '' || $text === $key) {
            $text = $this->translator->get($key, [], 'en');
        }
        if (! is_string($text) || $text === '' || $text === $key) {
            throw new RuntimeException('Telegram card-to-card protected delivery translation is unavailable.');
        }

        return $text;
    }

    private function safeResultCode(?string $resultCode): string
    {
        if (! is_string($resultCode) || preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $resultCode) !== 1) {
            throw new RuntimeException('Telegram card-to-card protected delivery result code is invalid.');
        }

        return $resultCode;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a non-negative integer.');
        }

        return $integer;
    }

    private function setEffectAuthority(Connection $connection, string $publicId, int $version): void
    {
        $connection->statement('SET @app_tg_c2c_protected_effect_authority = ?', [self::EFFECT_AUTHORITY]);
        $connection->statement('SET @app_tg_c2c_protected_public_id = ?', [$publicId]);
        $connection->statement('SET @app_tg_c2c_protected_expected_version = ?', [$version]);
    }

    private function clearEffectAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_tg_c2c_protected_effect_authority = NULL');
        $connection->statement('SET @app_tg_c2c_protected_public_id = NULL');
        $connection->statement('SET @app_tg_c2c_protected_expected_version = NULL');
    }
}
