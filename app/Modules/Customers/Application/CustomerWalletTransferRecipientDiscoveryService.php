<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exact active-customer discovery for the self-service wallet-transfer journey.
 * It exposes only the minimum Telegram/public identity needed for recipient preview.
 */
final readonly class CustomerWalletTransferRecipientDiscoveryService
{
    public function __construct(private DatabaseManager $database) {}

    public function searchForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $botId,
        string $query,
    ): CustomerWalletTransferRecipientSearchResult {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertBotId($botId);
        $this->assertActiveCustomer($subjectUserId);

        $normalized = trim($query);
        if ($normalized === '' || strlen($normalized) > 128 || ! mb_check_encoding($normalized, 'UTF-8')) {
            return CustomerWalletTransferRecipientSearchResult::notFound();
        }

        $builder = $this->baseQuery($subjectUserId, $botId);

        if (str_starts_with($normalized, '@')) {
            $username = substr($normalized, 1);
            if (preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1) {
                return CustomerWalletTransferRecipientSearchResult::notFound();
            }
            $builder->where('account.username', $username);
        } else {
            $publicId = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $normalized) === 1
                ? strtoupper($normalized)
                : null;
            $telegramUserId = preg_match('/\A[1-9][0-9]{0,19}\z/', $normalized) === 1
                ? $normalized
                : null;
            $username = preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $normalized) === 1
                ? $normalized
                : null;

            if ($publicId === null && $telegramUserId === null && $username === null) {
                return CustomerWalletTransferRecipientSearchResult::notFound();
            }

            $builder->where(function (Builder $exact) use ($publicId, $telegramUserId, $username): void {
                if ($publicId !== null) {
                    $exact->orWhere('user.public_id', $publicId);
                }
                if ($telegramUserId !== null) {
                    $exact->orWhere('account.telegram_user_id', $telegramUserId);
                }
                if ($username !== null) {
                    $exact->orWhere('account.username', $username);
                }
            });
        }

        /** @var Collection<int,object{internal_user_id:int|string,public_id:string,telegram_user_id:int|string,username:string|null,locale:string}> $rows */
        $rows = $builder
            ->orderBy('user.id')
            ->limit(3)
            ->get([
                'user.id as internal_user_id',
                'user.public_id',
                'account.telegram_user_id',
                'account.username',
                'user.locale',
            ]);

        if ($rows->isEmpty()) {
            return CustomerWalletTransferRecipientSearchResult::notFound();
        }

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$this->positiveDatabaseInt($row->internal_user_id, 'Wallet transfer recipient user ID')] = $row;
        }
        if (count($byUser) !== 1) {
            return CustomerWalletTransferRecipientSearchResult::ambiguous();
        }

        $row = array_values($byUser)[0];
        $publicId = $this->databaseString($row->public_id ?? null, 'Wallet transfer recipient public ID');
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new RuntimeException('Wallet transfer recipient public ID is invalid.');
        }
        $username = $row->username ?? null;
        if ($username !== null
            && (! is_string($username) || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1)) {
            throw new RuntimeException('Wallet transfer recipient Telegram username is invalid.');
        }

        return CustomerWalletTransferRecipientSearchResult::matched(
            new CustomerWalletTransferRecipient(
                $publicId,
                $this->databasePositiveIntString(
                    $row->telegram_user_id ?? null,
                    'Wallet transfer recipient Telegram user ID',
                ),
                $username === null ? null : $this->maskUsername($username),
                ($row->locale ?? null) === 'en' ? 'en' : 'fa',
            ),
        );
    }

    private function baseQuery(int $subjectUserId, string $botId): Builder
    {
        return $this->database->connection()
            ->table('users as user')
            ->join('telegram_accounts as account', 'account.user_id', '=', 'user.id')
            ->where('account.bot_id', $botId)
            ->where('user.id', '<>', $subjectUserId)
            ->where('user.account_type', 'customer')
            ->where('user.account_status', 'active');
    }

    private function assertActiveCustomer(int $userId): void
    {
        $row = $this->database->connection()->table('users')
            ->where('id', $userId)
            ->first(['account_type', 'account_status']);
        if ($row === null || $row->account_type !== 'customer' || $row->account_status !== 'active') {
            throw new AuthorizationException('Wallet transfer customer access denied.');
        }
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Wallet transfer recipient discovery self access denied.');
        }
    }

    private function assertBotId(string $botId): void
    {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $botId) !== 1) {
            throw new InvalidArgumentException('Wallet transfer recipient bot ID is invalid.');
        }
    }

    private function maskUsername(string $username): string
    {
        $length = strlen($username);
        if ($length <= 5) {
            return $username[0].str_repeat('*', max(1, $length - 2)).$username[$length - 1];
        }

        return substr($username, 0, 2).str_repeat('*', $length - 4).substr($username, -2);
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
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new RuntimeException($label.' exceeds the supported platform range.');
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
