<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramMenuConfigurationService
{
    private const VERSION_TARGET = 'telegram_menu_configuration_version';

    private const HEAD_TARGET = 'telegram_menu_configuration_head';

    public function __construct(
        private DatabaseManager $database,
        private TelegramMenuConfigurationMutationExecutor $executor,
        private TelegramConfigurationMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement CNT-002 CNT-003 ACL-002 SEC-002 QUA-001 QUA-004 */
    public function createVersion(
        string $menuKey,
        TelegramMenuConfigurationDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): TelegramMenuConfigurationVersion {
        TelegramMenuItemDefinition::assertMenuKey($menuKey);
        $this->assertDefinitionForMenu($menuKey, $definition);
        $payloadHash = $this->payloadHash([
            'definition_hash' => $definition->hash(),
            'menu_key' => $menuKey,
        ]);

        $replayed = $this->executor->replayIfExists(
            'telegram.menu.version.create',
            self::VERSION_TARGET,
            null,
            $payloadHash,
            $context,
        );
        if ($replayed !== null) {
            return $this->versionById($replayed->targetId, true);
        }

        $receipt = $this->executor->execute(
            'telegram.menu.version.create',
            self::VERSION_TARGET,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($menuKey, $definition, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $this->ensureHead($connection, $menuKey);
                $head = $this->lockedHead($connection, $menuKey);
                $version = (int) $head->next_version;
                $publicId = (string) Str::ulid();
                $createdAt = $this->timestamp();

                $id = (int) $connection->table('telegram_menu_configuration_versions')->insertGetId([
                    'public_id' => $publicId,
                    'menu_key' => $menuKey,
                    'version' => $version,
                    'definition_json' => $definition->json(),
                    'definition_hash' => $definition->hash(),
                    'created_by_administrator_id' => $context->actorAdministratorId,
                    'created_at' => $createdAt,
                ]);

                $connection->table('telegram_menu_configuration_heads')
                    ->where('id', (int) $head->id)
                    ->update([
                        'next_version' => $version + 1,
                        'updated_at' => $createdAt,
                    ]);

                return $this->audit->record(
                    $connection,
                    'telegram.menu.version.create',
                    self::VERSION_TARGET,
                    $id,
                    $context,
                    [],
                    [
                        'definition_hash' => $definition->hash(),
                        'menu_key' => $menuKey,
                        'request_payload_hash' => $payloadHash,
                        'version' => $version,
                    ],
                    true,
                );
            },
        );

        return $this->versionById($receipt->targetId, $receipt->replayed);
    }

    /** @requirement CNT-002 CNT-003 ACL-002 SEC-002 QUA-001 QUA-004 */
    public function publish(
        string $versionPublicId,
        int $expectedGeneration,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $version = $this->versionByPublicId($versionPublicId);
        $head = $this->head($version->menuKey);
        if ($expectedGeneration < 0 || $head === null) {
            throw new RuntimeException('Telegram menu publication generation is invalid.');
        }

        $payloadHash = $this->payloadHash([
            'expected_generation' => $expectedGeneration,
            'menu_key' => $version->menuKey,
            'version_public_id' => $version->publicId,
        ]);

        return $this->executor->execute(
            'telegram.menu.publish',
            self::HEAD_TARGET,
            (int) $head->id,
            $payloadHash,
            $context,
            fn (Connection $connection): TelegramConfigurationMutationReceipt => $this->activateVersion(
                $connection,
                'telegram.menu.publish',
                $version,
                $expectedGeneration,
                $payloadHash,
                $context,
            ),
        );
    }

    /** @requirement CNT-002 CNT-003 ACL-002 SEC-002 QUA-001 QUA-004 */
    public function rollback(
        string $menuKey,
        int $targetVersion,
        int $expectedGeneration,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        TelegramMenuItemDefinition::assertMenuKey($menuKey);
        if ($targetVersion < 1 || $expectedGeneration < 0) {
            throw new RuntimeException('Telegram menu rollback version/generation is invalid.');
        }
        $version = $this->versionByNumber($menuKey, $targetVersion);
        $head = $this->head($menuKey);
        if ($head === null) {
            throw new RuntimeException('Telegram menu configuration head does not exist.');
        }
        $payloadHash = $this->payloadHash([
            'expected_generation' => $expectedGeneration,
            'menu_key' => $menuKey,
            'target_version' => $targetVersion,
        ]);

        return $this->executor->execute(
            'telegram.menu.rollback',
            self::HEAD_TARGET,
            (int) $head->id,
            $payloadHash,
            $context,
            fn (Connection $connection): TelegramConfigurationMutationReceipt => $this->activateVersion(
                $connection,
                'telegram.menu.rollback',
                $version,
                $expectedGeneration,
                $payloadHash,
                $context,
            ),
        );
    }

    public function active(string $menuKey): ?TelegramMenuConfigurationVersion
    {
        TelegramMenuItemDefinition::assertMenuKey($menuKey);
        $head = $this->head($menuKey);
        if ($head === null || $head->active_version_id === null) {
            return null;
        }

        return $this->versionById((int) $head->active_version_id);
    }

    public function versionByPublicId(string $publicId): TelegramMenuConfigurationVersion
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new RuntimeException('Telegram menu version public ID is invalid.');
        }
        $id = $this->database->connection()
            ->table('telegram_menu_configuration_versions')
            ->where('public_id', $publicId)
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new RuntimeException('Telegram menu configuration version does not exist.');
        }

        return $this->versionById((int) $id);
    }

    /** @return list<TelegramMenuConfigurationVersion> */
    public function history(string $menuKey): array
    {
        TelegramMenuItemDefinition::assertMenuKey($menuKey);
        $rows = $this->database->connection()
            ->table('telegram_menu_configuration_versions')
            ->where('menu_key', $menuKey)
            ->orderByDesc('version')
            ->get(['id'])
            ->all();

        return array_map(
            fn (object $row): TelegramMenuConfigurationVersion => $this->versionById((int) $row->id),
            $rows,
        );
    }

    /** @return array{id:int,menu_key:string,active_version_id:?int,generation:int,next_version:int}|null */
    public function headSnapshot(string $menuKey): ?array
    {
        TelegramMenuItemDefinition::assertMenuKey($menuKey);
        $head = $this->head($menuKey);
        if ($head === null) {
            return null;
        }

        return [
            'id' => (int) $head->id,
            'menu_key' => (string) $head->menu_key,
            'active_version_id' => $head->active_version_id === null ? null : (int) $head->active_version_id,
            'generation' => (int) $head->generation,
            'next_version' => (int) $head->next_version,
        ];
    }

    private function activateVersion(
        Connection $connection,
        string $action,
        TelegramMenuConfigurationVersion $version,
        int $expectedGeneration,
        string $payloadHash,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $head = $this->lockedHead($connection, $version->menuKey);
        if ((int) $head->generation !== $expectedGeneration) {
            throw new RuntimeException('Telegram menu publication generation conflict.');
        }

        $before = [
            'active_version_id' => $head->active_version_id === null ? null : (int) $head->active_version_id,
            'generation' => (int) $head->generation,
            'menu_key' => (string) $head->menu_key,
            'request_payload_hash' => $payloadHash,
        ];
        if ($head->active_version_id !== null && (int) $head->active_version_id === $version->id) {
            return $this->audit->record(
                $connection,
                $action,
                self::HEAD_TARGET,
                (int) $head->id,
                $context,
                $before,
                $before,
                false,
            );
        }

        $newGeneration = $expectedGeneration + 1;
        $connection->table('telegram_menu_configuration_heads')
            ->where('id', (int) $head->id)
            ->update([
                'active_version_id' => $version->id,
                'generation' => $newGeneration,
                'updated_by_administrator_id' => $context->actorAdministratorId,
                'updated_at' => $this->timestamp(),
            ]);

        $after = [
            'active_version_id' => $version->id,
            'generation' => $newGeneration,
            'menu_key' => $version->menuKey,
            'request_payload_hash' => $payloadHash,
        ];

        return $this->audit->record(
            $connection,
            $action,
            self::HEAD_TARGET,
            (int) $head->id,
            $context,
            $before,
            $after,
            true,
        );
    }

    private function assertDefinitionForMenu(
        string $menuKey,
        TelegramMenuConfigurationDefinition $definition,
    ): void {
        $enabledSystem = [];
        foreach ($definition->items as $item) {
            if ($item->kind === TelegramMenuItemDefinition::KIND_SYSTEM) {
                if ($menuKey !== 'home'
                    || $item->actionType !== TelegramMenuItemDefinition::ACTION_REGISTERED
                    || $item->actionKey === null
                    || $item->key !== $item->actionKey) {
                    throw new RuntimeException('Telegram system menu item cannot be rebound.');
                }
                if ($item->enabled) {
                    $enabledSystem[$item->key] = true;
                }
            }
            if ($item->actionType === TelegramMenuItemDefinition::ACTION_SUBMENU
                && $item->submenuKey === $menuKey) {
                throw new RuntimeException('Telegram menu cannot open itself as a submenu.');
            }
        }

        if ($menuKey === 'home') {
            foreach ([
                TelegramMenuRegisteredAction::MyAccount->value,
                TelegramMenuRegisteredAction::MyServices->value,
            ] as $essential) {
                if (! isset($enabledSystem[$essential])) {
                    throw new RuntimeException('Telegram home menu must keep essential navigation enabled.');
                }
            }
        }
    }

    private function versionByNumber(string $menuKey, int $version): TelegramMenuConfigurationVersion
    {
        $id = $this->database->connection()
            ->table('telegram_menu_configuration_versions')
            ->where('menu_key', $menuKey)
            ->where('version', $version)
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new RuntimeException('Telegram menu configuration version does not exist.');
        }

        return $this->versionById((int) $id);
    }

    private function versionById(int $id, bool $replayed = false): TelegramMenuConfigurationVersion
    {
        /** @var object{id:int|string,public_id:string,menu_key:string,version:int|string,definition_json:string,definition_hash:string,created_by_administrator_id:int|string,created_at:string}|null $row */
        $row = $this->database->connection()
            ->table('telegram_menu_configuration_versions')
            ->where('id', $id)
            ->first([
                'id',
                'public_id',
                'menu_key',
                'version',
                'definition_json',
                'definition_hash',
                'created_by_administrator_id',
                'created_at',
            ]);
        if ($row === null) {
            throw new RuntimeException('Telegram menu configuration version does not exist.');
        }
        $definition = TelegramMenuConfigurationDefinition::restore((string) $row->definition_json);
        if (! hash_equals((string) $row->definition_hash, $definition->hash())) {
            throw new RuntimeException('Telegram menu configuration definition integrity failed.');
        }
        $activeId = $this->database->connection()
            ->table('telegram_menu_configuration_heads')
            ->where('menu_key', (string) $row->menu_key)
            ->value('active_version_id');

        return new TelegramMenuConfigurationVersion(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->menu_key,
            (int) $row->version,
            $definition,
            (int) $row->created_by_administrator_id,
            (string) $row->created_at,
            (is_int($activeId) || is_string($activeId)) && (int) $activeId === (int) $row->id,
            $replayed,
        );
    }

    /** @return object{id:int|string,menu_key:string,active_version_id:int|string|null,generation:int|string,next_version:int|string}|null */
    private function head(string $menuKey): ?object
    {
        return $this->database->connection()
            ->table('telegram_menu_configuration_heads')
            ->where('menu_key', $menuKey)
            ->first(['id', 'menu_key', 'active_version_id', 'generation', 'next_version']);
    }

    private function ensureHead(Connection $connection, string $menuKey): void
    {
        $timestamp = $this->timestamp();
        $connection->table('telegram_menu_configuration_heads')->insertOrIgnore([
            'menu_key' => $menuKey,
            'active_version_id' => null,
            'generation' => 0,
            'next_version' => 1,
            'updated_by_administrator_id' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @return object{id:int|string,menu_key:string,active_version_id:int|string|null,generation:int|string,next_version:int|string} */
    private function lockedHead(Connection $connection, string $menuKey): object
    {
        $head = $connection->table('telegram_menu_configuration_heads')
            ->where('menu_key', $menuKey)
            ->lockForUpdate()
            ->first(['id', 'menu_key', 'active_version_id', 'generation', 'next_version']);
        if ($head === null) {
            throw new RuntimeException('Telegram menu configuration head does not exist.');
        }

        return $head;
    }

    /** @param array<string,bool|int|string|null> $payload */
    private function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
