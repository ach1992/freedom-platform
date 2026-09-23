<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;
use Database\Seeders\AdministratorSearchAccessFoundationSeeder;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramAdministratorServiceSearchSource implements TelegramAdministratorSearchSource
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser(
            $actorUserId,
            AdministratorSearchPermissions::SERVICE_PERMISSION,
        );
    }

    /** @return list<TelegramAdministratorSearchItem> */
    public function search(int $actorUserId, string $botId, string $query): array
    {
        $this->administrators->authorizeUser(
            $actorUserId,
            AdministratorSearchPermissions::SERVICE_PERMISSION,
        );

        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $query) !== 1) {
            return [];
        }

        $row = $this->database->connection()
            ->table('service_subscriptions as service')
            ->join('users as user', 'user.id', '=', 'service.user_id')
            ->where('service.public_id', strtoupper($query))
            ->first([
                'service.public_id',
                'service.lifecycle_state',
                'user.public_id as owner_public_id',
            ]);

        if ($row === null) {
            return [];
        }

        return [new TelegramAdministratorSearchItem(
            'service',
            $this->databaseUlid($row->public_id ?? null, 'Administrator search Service public ID'),
            $this->databaseToken($row->lifecycle_state ?? null, 'Administrator search Service state'),
            $this->databaseUlid($row->owner_public_id ?? null, 'Administrator search Service owner public ID'),
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
}
