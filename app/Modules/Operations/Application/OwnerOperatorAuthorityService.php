<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Application\TelegramIdentityAccountService;
use App\Modules\Operations\Application\Contracts\OwnerAuthorityMutator;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class OwnerOperatorAuthorityService
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramIdentityAccountService $identityAccounts,
        private CustomerIdentityProfileWriter $customerProfiles,
        private OwnerAuthorityMutator $ownerAuthority,
        private Clock $clock,
    ) {}

    /** @return array{changed: bool, administrator_id: int, user_id: int} */
    public function bootstrap(string $botToken, int $telegramUserId, string $locale): array
    {
        $botId = $this->botId($botToken);
        $this->assertTelegramUserId($telegramUserId);
        $locale = $this->locale($locale);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $botId,
            $telegramUserId,
            $locale,
        ): array {
            $timestamp = $this->timestamp();
            $targetUserId = $this->targetUserId(
                $connection,
                $botId,
                $telegramUserId,
                $locale,
                $timestamp,
            );

            return $this->ownerAuthority->bootstrap($connection, $targetUserId, $timestamp);
        }, 3);
    }

    public function currentTelegramUserId(string $botToken): ?int
    {
        $botId = $this->botId($botToken);

        return $this->database->connection()->transaction(function (Connection $connection) use ($botId): ?int {
            $ownerUserId = $this->ownerAuthority->currentOwnerUserId($connection);
            if ($ownerUserId === null) {
                return null;
            }

            $telegramUserId = $this->identityAccounts->telegramUserIdForUser(
                $connection,
                $botId,
                $ownerUserId,
            );

            if ($telegramUserId === null) {
                throw new RuntimeException('Current Owner is not bound to the configured Telegram bot.');
            }

            return $telegramUserId;
        });
    }

    /**
     * The database remains authoritative after installation. OWNER_TELEGRAM_ID is
     * bootstrap input only and changing it never invokes this mutation.
     *
     * @return array{
     *     changed: bool,
     *     previous_administrator_id: ?int,
     *     administrator_id: int,
     *     user_id: int,
     *     cancelled_transfer_count: int
     * }
     */
    public function recover(
        string $botToken,
        int $telegramUserId,
        ?int $expectedCurrentTelegramUserId,
        string $reason,
        string $locale,
    ): array {
        $botId = $this->botId($botToken);
        $this->assertTelegramUserId($telegramUserId);

        if ($expectedCurrentTelegramUserId !== null) {
            $this->assertTelegramUserId($expectedCurrentTelegramUserId);
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new RuntimeException('Owner recovery reason is invalid.');
        }

        $locale = $this->locale($locale);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $botId,
            $telegramUserId,
            $expectedCurrentTelegramUserId,
            $reason,
            $locale,
        ): array {
            $timestamp = $this->timestamp();
            $currentOwnerUserId = $this->ownerAuthority->currentOwnerUserId($connection);
            $currentTelegramUserId = null;

            if ($currentOwnerUserId !== null) {
                $currentTelegramUserId = $this->identityAccounts->telegramUserIdForUser(
                    $connection,
                    $botId,
                    $currentOwnerUserId,
                );

                if ($currentTelegramUserId === null) {
                    throw new RuntimeException('Current Owner is not bound to the configured Telegram bot.');
                }

                if ($expectedCurrentTelegramUserId === null) {
                    throw new RuntimeException('Expected current Owner Telegram ID is required.');
                }

                if ($currentTelegramUserId !== $expectedCurrentTelegramUserId) {
                    throw new RuntimeException('Expected current Owner Telegram ID does not match authoritative state.');
                }
            } elseif ($expectedCurrentTelegramUserId !== null) {
                throw new RuntimeException('Expected current Owner was supplied but no database Owner exists.');
            }

            $targetUserId = $this->targetUserId(
                $connection,
                $botId,
                $telegramUserId,
                $locale,
                $timestamp,
            );

            return $this->ownerAuthority->recover(
                $connection,
                $targetUserId,
                $currentOwnerUserId,
                $reason,
                $timestamp,
            );
        }, 3);
    }

    private function targetUserId(
        Connection $connection,
        string $botId,
        int $telegramUserId,
        string $locale,
        string $timestamp,
    ): int {
        $identity = $this->identityAccounts->synchronize(
            $connection,
            $botId,
            $telegramUserId,
            null,
            null,
            $locale,
            false,
            $timestamp,
        );

        $this->customerProfiles->ensure($connection, $identity->userId, $timestamp);

        return $identity->userId;
    }

    private function botId(string $botToken): string
    {
        if (preg_match('/\A([1-9][0-9]{5,19}):[A-Za-z0-9_-]{20,}\z/', trim($botToken), $matches) !== 1) {
            throw new RuntimeException('Telegram bot token is not configured correctly.');
        }

        return $matches[1];
    }

    private function assertTelegramUserId(int $telegramUserId): void
    {
        if ($telegramUserId < 1) {
            throw new RuntimeException('Telegram user ID must be positive.');
        }
    }

    private function locale(string $locale): string
    {
        return $locale === 'en' ? 'en' : 'fa';
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
