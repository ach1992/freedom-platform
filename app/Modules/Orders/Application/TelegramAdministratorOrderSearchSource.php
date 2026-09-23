<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;
use Database\Seeders\AdministratorSearchAccessFoundationSeeder;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramAdministratorOrderSearchSource implements TelegramAdministratorSearchSource
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser(
            $actorUserId,
            AdministratorSearchAccessFoundationSeeder::ORDER_PERMISSION,
        );
    }

    /** @return list<TelegramAdministratorSearchItem> */
    public function search(int $actorUserId, string $botId, string $query): array
    {
        $this->administrators->authorizeUser(
            $actorUserId,
            AdministratorSearchAccessFoundationSeeder::ORDER_PERMISSION,
        );

        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $query) !== 1) {
            return [];
        }

        $row = $this->database->connection()
            ->table('orders as order_row')
            ->join('users as user', 'user.id', '=', 'order_row.user_id')
            ->where('order_row.public_id', strtoupper($query))
            ->first([
                'order_row.public_id',
                'order_row.state',
                'order_row.total_amount_irr',
                'user.public_id as owner_public_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [new TelegramAdministratorSearchItem(
            'order',
            $this->databaseUlid($row->public_id ?? null, 'Administrator search Order public ID'),
            $this->databaseToken($row->state ?? null, 'Administrator search Order state'),
            $this->databaseUlid($row->owner_public_id ?? null, 'Administrator search Order owner public ID'),
            amountIrr: $this->databaseNonNegativeInt($row->total_amount_irr ?? null, 'Administrator search Order amount'),
        )];
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
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseNonNegativeInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
