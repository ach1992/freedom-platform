<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\AccessControl\Application\AdministratorRoleContextReader;
use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class SupportTicketCategoryManagementService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private AdministratorRoleContextReader $roles,
        private Clock $clock,
    ) {}

    /** @return list<SupportTicketCategoryManagementSnapshot> */
    public function categories(int $actorUserId): array
    {
        $this->authorize($actorUserId);

        $rows = $this->database->connection()->table('support_ticket_categories')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get($this->columns());

        $categories = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $categories[] = $this->snapshot($row);
        }

        return $categories;
    }

    public function category(int $actorUserId, string $code): SupportTicketCategoryManagementSnapshot
    {
        $this->authorize($actorUserId);

        return $this->read($this->database->connection(), $this->normalizeCode($code));
    }

    public function setName(
        int $actorUserId,
        string $code,
        string $locale,
        string $name,
    ): SupportTicketCategoryManagementSnapshot {
        $field = match ($locale) {
            'fa' => 'name_fa',
            'en' => 'name_en',
            default => throw new InvalidArgumentException('Support category locale is invalid.'),
        };
        $normalizedName = $this->normalizeName($name);

        return $this->mutate(
            $actorUserId,
            $code,
            static function (stdClass $row) use ($field, $normalizedName): array {
                if ((string) $row->{$field} === $normalizedName) {
                    return [];
                }

                return [$field => $normalizedName];
            },
        );
    }

    public function setSortOrder(
        int $actorUserId,
        string $code,
        int $sortOrder,
    ): SupportTicketCategoryManagementSnapshot {
        if ($sortOrder < 0 || $sortOrder > 4_294_967_295) {
            throw new InvalidArgumentException('Support category sort order is invalid.');
        }

        return $this->mutate(
            $actorUserId,
            $code,
            static fn (stdClass $row): array => (int) $row->sort_order === $sortOrder
                ? []
                : ['sort_order' => $sortOrder],
        );
    }

    public function setActive(
        int $actorUserId,
        string $code,
        bool $active,
    ): SupportTicketCategoryManagementSnapshot {
        return $this->mutate(
            $actorUserId,
            $code,
            static fn (stdClass $row): array => (bool) $row->is_active === $active
                ? []
                : ['is_active' => $active],
        );
    }

    public function setRouteRole(
        int $actorUserId,
        string $code,
        ?string $routeRoleCode,
    ): SupportTicketCategoryManagementSnapshot {
        return $this->mutate(
            $actorUserId,
            $code,
            function (stdClass $row) use ($routeRoleCode): array {
                $normalizedRole = $routeRoleCode === null
                    ? null
                    : $this->roles->requireActiveRole($routeRoleCode);
                $current = $row->route_role_code === null ? null : (string) $row->route_role_code;

                return $current === $normalizedRole
                    ? []
                    : ['route_role_code' => $normalizedRole];
            },
        );
    }

    /**
     * @param  Closure(stdClass):array<string,bool|int|string|null>  $changes
     */
    private function mutate(
        int $actorUserId,
        string $code,
        Closure $changes,
    ): SupportTicketCategoryManagementSnapshot {
        $normalizedCode = $this->normalizeCode($code);

        return $this->database->connection()->transaction(function (Connection $connection) use ($actorUserId, $normalizedCode, $changes): SupportTicketCategoryManagementSnapshot {
            $this->authorize($actorUserId);
            /** @var stdClass|null $row */
            $row = $connection->table('support_ticket_categories')
                ->where('code', $normalizedCode)
                ->lockForUpdate()
                ->first($this->columns());
            if (! $row instanceof stdClass) {
                throw new RuntimeException('Support category does not exist.');
            }

            $updates = $changes($row);
            if ($updates !== []) {
                $updates['updated_at'] = $this->timestamp();
                $connection->table('support_ticket_categories')
                    ->where('id', (int) $row->id)
                    ->update($updates);
            }

            return $this->read($connection, $normalizedCode);
        }, 3);
    }

    private function read(Connection $connection, string $code): SupportTicketCategoryManagementSnapshot
    {
        /** @var stdClass|null $row */
        $row = $connection->table('support_ticket_categories')
            ->where('code', $code)
            ->first($this->columns());
        if (! $row instanceof stdClass) {
            throw new RuntimeException('Support category does not exist.');
        }

        return $this->snapshot($row);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return ['id', 'code', 'name_fa', 'name_en', 'route_role_code', 'sort_order', 'is_active', 'updated_at'];
    }

    private function snapshot(stdClass $row): SupportTicketCategoryManagementSnapshot
    {
        return new SupportTicketCategoryManagementSnapshot(
            (int) $row->id,
            (string) $row->code,
            (string) $row->name_fa,
            (string) $row->name_en,
            $row->route_role_code === null ? null : (string) $row->route_role_code,
            (int) $row->sort_order,
            (bool) $row->is_active,
            (string) $row->updated_at,
        );
    }

    private function authorize(int $actorUserId): int
    {
        return $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);
    }

    private function normalizeCode(string $code): string
    {
        $normalized = trim($code);
        if ($normalized === '' || strlen($normalized) > 64 || preg_match('/[\x00-\x20\x7f]/', $normalized) === 1) {
            throw new InvalidArgumentException('Support category code is invalid.');
        }

        return $normalized;
    }

    private function normalizeName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '' || mb_strlen($normalized) > 191 || preg_match('/[\x00-\x1f\x7f]/u', $normalized) === 1) {
            throw new InvalidArgumentException('Support category name is invalid.');
        }

        return $normalized;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
