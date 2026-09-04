<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramCardToCardProtectedDeliveryQueueService
{
    public const OUTBOX_EVENT_TYPE = 'telegram.c2c_protected_copy.requested';

    public const OUTBOX_CONTRACT_VERSION = 1;

    public const OUTBOX_AGGREGATE_TYPE = 'telegram_c2c_protected_delivery';

    public const OUTBOX_EVENT_KEY_PREFIX = 'telegram-c2c-protected-copy:';

    private const INSERT_AUTHORITY = 'telegram_c2c_protected_insert_v1';

    public function __construct(
        private DatabaseManager $database,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @requirement C2C-001 DAT-002 DAT-003 SEC-002 SEC-008 INT-001 INT-002 QUA-001 QUA-004 */
    public function queue(
        int $telegramAccountId,
        int $userId,
        int $telegramUserId,
        string $reservationPublicId,
        string $locale,
        string $requestKey,
        string $correlationId,
    ): string {
        if ($telegramAccountId < 1 || $userId < 1 || $telegramUserId < 1) {
            throw new AuthorizationException('Telegram card-to-card protected delivery recipient is unavailable.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1) {
            throw new AuthorizationException('Telegram card-to-card protected delivery reservation is unavailable.');
        }
        if (! in_array($locale, ['fa', 'en'], true)) {
            throw new RuntimeException('Telegram card-to-card protected delivery locale is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $requestKey) !== 1) {
            throw new RuntimeException('Telegram card-to-card protected delivery request identity is invalid.');
        }
        if (preg_match('/\A[a-z0-9_.:-]{8,64}\z/', $correlationId) !== 1) {
            throw new RuntimeException('Telegram card-to-card protected delivery correlation identity is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $telegramAccountId,
            $userId,
            $telegramUserId,
            $reservationPublicId,
            $locale,
            $requestKey,
            $correlationId,
        ): string {
            $account = $connection->table('telegram_accounts')
                ->where('id', $telegramAccountId)
                ->lockForUpdate()
                ->first(['id', 'user_id', 'telegram_user_id', 'is_bot']);
            if ($account === null
                || (int) $account->user_id !== $userId
                || (int) $account->telegram_user_id !== $telegramUserId
                || (bool) $account->is_bot) {
                throw new AuthorizationException('Telegram card-to-card protected delivery recipient is unavailable.');
            }

            $reservationPublicId = strtoupper($reservationPublicId);
            $requestKeyHash = hash('sha256', $requestKey);
            $existing = $connection->table('telegram_c2c_protected_deliveries')
                ->where('c2c_reservation_public_id', $reservationPublicId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertReplay($existing, $telegramAccountId, $userId, $telegramUserId, $reservationPublicId, $locale, $requestKeyHash, $correlationId);

                return (string) $existing->public_id;
            }

            $publicId = (string) Str::ulid();
            $eventId = (string) Str::uuid();
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $this->setInsertAuthority($connection, $publicId, $telegramAccountId, $userId, $telegramUserId, $reservationPublicId, $requestKeyHash, $eventId, $correlationId);
            try {
                $inserted = $connection->table('telegram_c2c_protected_deliveries')->insertOrIgnore([
                    'public_id' => $publicId,
                    'request_key_hash' => $requestKeyHash,
                    'c2c_reservation_public_id' => $reservationPublicId,
                    'telegram_account_id' => $telegramAccountId,
                    'user_id' => $userId,
                    'telegram_user_id' => $telegramUserId,
                    'locale' => $locale,
                    'outbox_event_id' => $eventId,
                    'correlation_id' => $correlationId,
                    'state' => 'prepared',
                    'state_version' => 1,
                    'provider_attempts' => 0,
                    'provider_boundary_started_at' => null,
                    'completed_at' => null,
                    'telegram_message_id' => null,
                    'result_code' => null,
                    'retry_after_seconds' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } finally {
                $this->clearInsertAuthority($connection);
            }
            if ($inserted !== 1) {
                $existing = $connection->table('telegram_c2c_protected_deliveries')
                    ->where('c2c_reservation_public_id', $reservationPublicId)
                    ->lockForUpdate()
                    ->first();
                if ($existing === null) {
                    throw new RuntimeException('Telegram card-to-card protected delivery collision is unresolved.');
                }
                $this->assertReplay($existing, $telegramAccountId, $userId, $telegramUserId, $reservationPublicId, $locale, $requestKeyHash, $correlationId);

                return (string) $existing->public_id;
            }

            $payload = new SafeOutboxPayload(['delivery_public_id' => $publicId]);
            $eventKey = self::OUTBOX_EVENT_KEY_PREFIX.$publicId;
            $publishedEventId = $this->outbox->publish(
                $eventId,
                $eventKey,
                self::OUTBOX_EVENT_TYPE,
                self::OUTBOX_AGGREGATE_TYPE,
                $publicId,
                $payload,
                $correlationId,
                self::OUTBOX_CONTRACT_VERSION,
            );
            if (! hash_equals($eventId, $publishedEventId)) {
                throw new RuntimeException('Telegram card-to-card protected delivery Outbox identity was unexpectedly replayed.');
            }
            $this->outbox->releaseForDispatch(
                $eventId,
                $eventKey,
                self::OUTBOX_EVENT_TYPE,
                self::OUTBOX_AGGREGATE_TYPE,
                $publicId,
                $payload,
                $correlationId,
                self::OUTBOX_CONTRACT_VERSION,
            );

            return $publicId;
        }, 3);
    }

    private function assertReplay(
        object $row,
        int $telegramAccountId,
        int $userId,
        int $telegramUserId,
        string $reservationPublicId,
        string $locale,
        string $requestKeyHash,
        string $correlationId,
    ): void {
        if ((int) $row->telegram_account_id !== $telegramAccountId
            || (int) $row->user_id !== $userId
            || (int) $row->telegram_user_id !== $telegramUserId
            || ! hash_equals((string) $row->c2c_reservation_public_id, $reservationPublicId)
            || ! hash_equals((string) $row->request_key_hash, $requestKeyHash)
            || ! hash_equals((string) $row->locale, $locale)
            || ! hash_equals((string) $row->correlation_id, $correlationId)) {
            throw new AuthorizationException('Telegram card-to-card protected delivery replay does not match its original authority.');
        }
    }

    private function setInsertAuthority(
        Connection $connection,
        string $publicId,
        int $telegramAccountId,
        int $userId,
        int $telegramUserId,
        string $reservationPublicId,
        string $requestKeyHash,
        string $eventId,
        string $correlationId,
    ): void {
        $connection->statement('SET @app_tg_c2c_protected_insert_authority = ?', [self::INSERT_AUTHORITY]);
        $connection->statement('SET @app_tg_c2c_protected_public_id = ?', [$publicId]);
        $connection->statement('SET @app_tg_c2c_protected_telegram_account_id = ?', [$telegramAccountId]);
        $connection->statement('SET @app_tg_c2c_protected_user_id = ?', [$userId]);
        $connection->statement('SET @app_tg_c2c_protected_telegram_user_id = ?', [$telegramUserId]);
        $connection->statement('SET @app_tg_c2c_protected_reservation_public_id = ?', [$reservationPublicId]);
        $connection->statement('SET @app_tg_c2c_protected_request_key_hash = ?', [$requestKeyHash]);
        $connection->statement('SET @app_tg_c2c_protected_outbox_event_id = ?', [$eventId]);
        $connection->statement('SET @app_tg_c2c_protected_correlation_id = ?', [$correlationId]);
    }

    private function clearInsertAuthority(Connection $connection): void
    {
        foreach ([
            'insert_authority', 'public_id', 'telegram_account_id', 'user_id', 'telegram_user_id',
            'reservation_public_id', 'request_key_hash', 'outbox_event_id', 'correlation_id',
        ] as $suffix) {
            $connection->statement('SET @app_tg_c2c_protected_'.$suffix.' = NULL');
        }
    }
}
