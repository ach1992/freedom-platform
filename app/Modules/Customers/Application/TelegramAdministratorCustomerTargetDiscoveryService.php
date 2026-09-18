<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Application\TelegramAdministratorCustomerTarget;
use App\Modules\Telegram\Application\TelegramAdministratorCustomerTargetSearchResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramAdministratorCustomerTargetDiscoveryService implements TelegramAdministratorCustomerTargetDiscovery
{
    private const PERMISSION = 'identity.customers.view';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser($actorUserId, self::PERMISSION);
    }

    public function search(
        int $actorUserId,
        string $botId,
        string $query,
    ): TelegramAdministratorCustomerTargetSearchResult {
        $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertBotId($botId);

        $normalized = trim($query);
        if ($normalized === '' || strlen($normalized) > 128 || ! mb_check_encoding($normalized, 'UTF-8')) {
            return TelegramAdministratorCustomerTargetSearchResult::notFound();
        }

        $builder = $this->baseQuery($botId);

        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $normalized) === 1) {
            $builder->where('user.public_id', strtoupper($normalized));
        } elseif (preg_match('/\A[1-9][0-9]{0,19}\z/', $normalized) === 1) {
            $builder->where('account.telegram_user_id', $normalized);
        } else {
            $username = str_starts_with($normalized, '@') ? substr($normalized, 1) : $normalized;
            if (preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1) {
                return TelegramAdministratorCustomerTargetSearchResult::notFound();
            }
            $builder->whereRaw('LOWER(account.username) = ?', [strtolower($username)]);
        }

        $rows = $builder
            ->orderBy('user.id')
            ->limit(3)
            ->get([
                'user.id as internal_user_id',
                'user.public_id',
                'user.account_type',
                'user.account_status',
                'user.locale',
                'account.telegram_user_id',
                'account.username',
            ]);

        if ($rows->isEmpty()) {
            return TelegramAdministratorCustomerTargetSearchResult::notFound();
        }

        $byUser = [];
        foreach ($rows as $row) {
            $internalUserId = $this->positiveDatabaseInt($row->internal_user_id ?? null, 'Customer target internal user ID');
            $byUser[$internalUserId] = $row;
        }
        if (count($byUser) !== 1) {
            return TelegramAdministratorCustomerTargetSearchResult::ambiguous();
        }

        return TelegramAdministratorCustomerTargetSearchResult::matched(
            $this->target($actorUserId, $botId, array_values($byUser)[0]),
        );
    }

    public function resolve(
        int $actorUserId,
        string $botId,
        string $selectionToken,
    ): TelegramAdministratorCustomerTarget {
        $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertBotId($botId);
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new AuthorizationException('Telegram administrator customer target is unavailable.');
        }

        $row = $this->baseQuery($botId)
            ->whereRaw(
                "LEFT(SHA2(CONCAT('telegram-admin-customer-target-v1:', ?, ':', ?, ':', user.public_id), 256), 40) = ?",
                [(string) $actorUserId, $botId, $selectionToken],
            )
            ->first([
                'user.id as internal_user_id',
                'user.public_id',
                'user.account_type',
                'user.account_status',
                'user.locale',
                'account.telegram_user_id',
                'account.username',
            ]);

        if ($row === null) {
            throw new AuthorizationException('Telegram administrator customer target is unavailable.');
        }

        return $this->target($actorUserId, $botId, $row);
    }

    private function baseQuery(string $botId): Builder
    {
        return $this->database->connection()
            ->table('users as user')
            ->join('telegram_accounts as account', 'account.user_id', '=', 'user.id')
            ->where('account.bot_id', $botId)
            ->where('user.account_type', 'customer')
            ->where('user.account_status', '<>', 'deleted');
    }

    private function target(int $actorUserId, string $botId, object $row): TelegramAdministratorCustomerTarget
    {
        $publicId = $this->databaseString($row->public_id ?? null, 'Customer target public ID');
        $telegramUserId = $this->databasePositiveIntString(
            $row->telegram_user_id ?? null,
            'Customer target Telegram user ID',
        );
        $username = $row->username ?? null;
        if ($username !== null
            && (! is_string($username) || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1)) {
            throw new RuntimeException('Customer target Telegram username is invalid.');
        }

        return new TelegramAdministratorCustomerTarget(
            $this->selectionToken($actorUserId, $botId, $publicId),
            $telegramUserId,
            $publicId,
            $this->databaseString($row->account_type ?? null, 'Customer target account type'),
            $this->databaseString($row->account_status ?? null, 'Customer target account status'),
            $username === null ? null : $this->maskUsername($username),
            ($row->locale ?? null) === 'en' ? 'en' : 'fa',
        );
    }

    private function selectionToken(int $actorUserId, string $botId, string $publicId): string
    {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Telegram administrator customer target actor is invalid.');
        }

        return substr(
            hash('sha256', "telegram-admin-customer-target-v1:{$actorUserId}:{$botId}:{$publicId}"),
            0,
            40,
        );
    }

    private function maskUsername(string $username): string
    {
        $length = strlen($username);
        if ($length <= 5) {
            return $username[0].str_repeat('*', max(1, $length - 2)).$username[$length - 1];
        }

        return substr($username, 0, 2).str_repeat('*', $length - 4).substr($username, -2);
    }

    private function assertBotId(string $botId): void
    {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $botId) !== 1) {
            throw new InvalidArgumentException('Telegram administrator customer target bot ID is invalid.');
        }
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databasePositiveIntString(mixed $value, string $label): string
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException($label.' is invalid.');
            }

            return (string) $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,19}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $validated;
    }
}
