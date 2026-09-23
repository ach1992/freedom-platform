<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class TelegramAdministratorIdentitySearchSource implements TelegramAdministratorSearchSource
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

    /** @return list<TelegramAdministratorSearchItem> */
    public function search(int $actorUserId, string $botId, string $query): array
    {
        $this->administrators->authorizeUser($actorUserId, self::PERMISSION);

        $builder = $this->database->connection()
            ->table('users as user')
            ->leftJoin('telegram_accounts as account', function ($join) use ($botId): void {
                $join->on('account.user_id', '=', 'user.id')
                    ->where('account.bot_id', '=', $botId);
            })
            ->where('user.account_status', '<>', 'deleted');

        if (str_starts_with($query, '@')) {
            $username = substr($query, 1);
            if (preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) !== 1) {
                return [];
            }
            $builder->where('account.username', $username);
        } else {
            $publicId = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $query) === 1
                ? strtoupper($query)
                : null;
            $telegramUserId = preg_match('/\A[1-9][0-9]{0,19}\z/', $query) === 1 ? $query : null;
            $username = preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $query) === 1 ? $query : null;

            if ($publicId === null && $telegramUserId === null && $username === null) {
                return [];
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

        $rows = $builder
            ->orderBy('user.id')
            ->limit(4)
            ->get([
                'user.public_id',
                'user.account_type',
                'user.account_status',
                'account.username',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $publicId = $this->databaseUlid($row->public_id ?? null, 'Administrator search user public ID');
            $username = $row->username ?? null;
            if ($username !== null && ! is_string($username)) {
                throw new RuntimeException('Administrator search Telegram username is invalid.');
            }
            $items[] = new TelegramAdministratorSearchItem(
                'user',
                $publicId,
                $this->databaseToken($row->account_type ?? null, 'Administrator search user account type')
                    .':'.$this->databaseToken($row->account_status ?? null, 'Administrator search user account status'),
                reference: $username === null ? null : '@'.$this->maskUsername($username),
                referenceMasked: $username !== null,
            );
        }

        return $items;
    }

    private function maskUsername(string $username): string
    {
        $length = strlen($username);
        if ($length < 3) {
            return str_repeat('*', max(1, $length));
        }

        return substr($username, 0, 1).str_repeat('*', max(1, $length - 2)).substr($username, -1);
    }

    private function databaseUlid(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseToken(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }
}
