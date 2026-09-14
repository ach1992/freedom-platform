<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class LocalizationOverrideService
{
    private const MANAGE_PERMISSION = 'localization.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private LocalizationTemplateCatalog $templates,
        private Clock $clock,
    ) {}

    /** @return array{translation_key:string,locale:string,default:string,override:?string,effective:string,version:?int} */
    public function view(int $actorAdministratorId, string $key, string $locale): array
    {
        $this->authorizeRead($actorAdministratorId);
        $default = $this->templates->template($key, $locale);

        /** @var object{override_value:?string,version:int|string}|null $row */
        $row = $this->database->connection()->table('localization_overrides')
            ->where('translation_key', $key)
            ->where('locale', $locale)
            ->first(['override_value', 'version']);

        $override = $row?->override_value;

        return [
            'translation_key' => $key,
            'locale' => $locale,
            'default' => $default,
            'override' => $override,
            'effective' => $override ?? $default,
            'version' => $row === null ? null : (int) $row->version,
        ];
    }

    /** @return array{translation_key:string,locale:string,default:string,preview:string,placeholders:list<string>} */
    public function preview(int $actorAdministratorId, string $key, string $locale, string $candidate): array
    {
        $this->authorizeRead($actorAdministratorId);
        $validated = $this->templates->validateOverride($key, $locale, $candidate);
        $default = $this->templates->template($key, $locale);

        return [
            'translation_key' => $key,
            'locale' => $locale,
            'default' => $default,
            'preview' => $validated,
            'placeholders' => $this->templates->placeholders($default),
        ];
    }

    /** @return list<array{version:int,action:string,override:?string,actor_administrator_id:int,created_at:string}> */
    public function history(int $actorAdministratorId, string $key, string $locale): array
    {
        $this->authorizeRead($actorAdministratorId);
        $this->templates->template($key, $locale);

        $overrideId = $this->database->connection()->table('localization_overrides')
            ->where('translation_key', $key)
            ->where('locale', $locale)
            ->value('id');
        if (! is_int($overrideId) && ! is_string($overrideId)) {
            return [];
        }

        return array_values($this->database->connection()->table('localization_override_versions')
            ->where('localization_override_id', (int) $overrideId)
            ->orderByDesc('version')
            ->get(['version', 'action', 'override_value', 'actor_administrator_id', 'created_at'])
            ->map(static fn (object $row): array => [
                'version' => (int) $row->version,
                'action' => (string) $row->action,
                'override' => $row->override_value === null ? null : (string) $row->override_value,
                'actor_administrator_id' => (int) $row->actor_administrator_id,
                'created_at' => (string) $row->created_at,
            ])
            ->all());
    }

    public function set(
        string $key,
        string $locale,
        string $value,
        ?int $expectedVersion,
        LocalizationChangeContext $context,
    ): LocalizationOverrideReceipt {
        $value = $this->templates->validateOverride($key, $locale, $value);
        if ($expectedVersion !== null && $expectedVersion < 1) {
            throw new RuntimeException('Localization override expected version is invalid.');
        }

        $action = 'localization.override.set';
        $payloadHash = $this->payloadHash($key, $locale, $value, $expectedVersion, null);

        return $this->execute($action, $key, $locale, $payloadHash, $context, function (Connection $connection) use ($key, $locale, $value, $expectedVersion, $context, $action, $payloadHash): LocalizationOverrideReceipt {
            $current = $this->lockCurrent($connection, $key, $locale);
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');

            if ($current === null) {
                if ($expectedVersion !== null) {
                    throw new RuntimeException('Localization override version conflict.');
                }

                $overrideId = (int) $connection->table('localization_overrides')->insertGetId([
                    'translation_key' => $key,
                    'locale' => $locale,
                    'override_value' => $value,
                    'version' => 1,
                    'updated_by_administrator_id' => $context->actorAdministratorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->recordVersion($connection, $overrideId, 1, 'set', $value, $context, $now);
                $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, 1, true);
                $this->recordAudit($connection, $receipt, $context, $payloadHash, null, $value);

                return $receipt;
            }

            $this->assertExpectedVersion((int) $current->version, $expectedVersion);
            $overrideId = (int) $current->id;
            $currentVersion = (int) $current->version;
            $currentValue = $current->override_value === null ? null : (string) $current->override_value;
            if ($currentValue === $value) {
                $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $currentVersion, false);
                $this->recordAudit($connection, $receipt, $context, $payloadHash, $currentValue, $currentValue);

                return $receipt;
            }

            $nextVersion = $currentVersion + 1;
            $connection->table('localization_overrides')->where('id', $overrideId)->update([
                'override_value' => $value,
                'version' => $nextVersion,
                'updated_by_administrator_id' => $context->actorAdministratorId,
                'updated_at' => $now,
            ]);
            $this->recordVersion($connection, $overrideId, $nextVersion, 'set', $value, $context, $now);
            $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $nextVersion, true);
            $this->recordAudit($connection, $receipt, $context, $payloadHash, $currentValue, $value);

            return $receipt;
        });
    }

    public function reset(
        string $key,
        string $locale,
        int $expectedVersion,
        LocalizationChangeContext $context,
    ): LocalizationOverrideReceipt {
        $this->templates->template($key, $locale);
        if ($expectedVersion < 1) {
            throw new RuntimeException('Localization override expected version is invalid.');
        }

        $action = 'localization.override.reset';
        $payloadHash = $this->payloadHash($key, $locale, null, $expectedVersion, null);

        return $this->execute($action, $key, $locale, $payloadHash, $context, function (Connection $connection) use ($key, $locale, $expectedVersion, $context, $action, $payloadHash): LocalizationOverrideReceipt {
            $current = $this->lockCurrent($connection, $key, $locale);
            if ($current === null) {
                throw new RuntimeException('Localization override does not exist.');
            }
            $this->assertExpectedVersion((int) $current->version, $expectedVersion);

            $overrideId = (int) $current->id;
            $currentVersion = (int) $current->version;
            $currentValue = $current->override_value === null ? null : (string) $current->override_value;
            if ($currentValue === null) {
                $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $currentVersion, false);
                $this->recordAudit($connection, $receipt, $context, $payloadHash, null, null);

                return $receipt;
            }

            $nextVersion = $currentVersion + 1;
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $connection->table('localization_overrides')->where('id', $overrideId)->update([
                'override_value' => null,
                'version' => $nextVersion,
                'updated_by_administrator_id' => $context->actorAdministratorId,
                'updated_at' => $now,
            ]);
            $this->recordVersion($connection, $overrideId, $nextVersion, 'reset', null, $context, $now);
            $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $nextVersion, true);
            $this->recordAudit($connection, $receipt, $context, $payloadHash, $currentValue, null);

            return $receipt;
        });
    }

    public function restore(
        string $key,
        string $locale,
        int $sourceVersion,
        int $expectedVersion,
        LocalizationChangeContext $context,
    ): LocalizationOverrideReceipt {
        $this->templates->template($key, $locale);
        if ($sourceVersion < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Localization override version is invalid.');
        }

        $action = 'localization.override.restore';
        $payloadHash = $this->payloadHash($key, $locale, null, $expectedVersion, $sourceVersion);

        return $this->execute($action, $key, $locale, $payloadHash, $context, function (Connection $connection) use ($key, $locale, $sourceVersion, $expectedVersion, $context, $action, $payloadHash): LocalizationOverrideReceipt {
            $current = $this->lockCurrent($connection, $key, $locale);
            if ($current === null) {
                throw new RuntimeException('Localization override does not exist.');
            }
            $this->assertExpectedVersion((int) $current->version, $expectedVersion);

            /** @var object{override_value:?string}|null $historical */
            $historical = $connection->table('localization_override_versions')
                ->where('localization_override_id', (int) $current->id)
                ->where('version', $sourceVersion)
                ->first(['override_value']);
            if ($historical === null || $historical->override_value === null) {
                throw new RuntimeException('Localization override restore source is unavailable.');
            }

            $restoreValue = $this->templates->validateOverride($key, $locale, (string) $historical->override_value);
            $overrideId = (int) $current->id;
            $currentVersion = (int) $current->version;
            $currentValue = $current->override_value === null ? null : (string) $current->override_value;
            if ($currentValue === $restoreValue) {
                $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $currentVersion, false);
                $this->recordAudit($connection, $receipt, $context, $payloadHash, $currentValue, $currentValue);

                return $receipt;
            }

            $nextVersion = $currentVersion + 1;
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $connection->table('localization_overrides')->where('id', $overrideId)->update([
                'override_value' => $restoreValue,
                'version' => $nextVersion,
                'updated_by_administrator_id' => $context->actorAdministratorId,
                'updated_at' => $now,
            ]);
            $this->recordVersion($connection, $overrideId, $nextVersion, 'restore', $restoreValue, $context, $now);
            $receipt = new LocalizationOverrideReceipt($action, $overrideId, $key, $locale, $nextVersion, true);
            $this->recordAudit($connection, $receipt, $context, $payloadHash, $currentValue, $restoreValue);

            return $receipt;
        });
    }

    /** @param callable(Connection): LocalizationOverrideReceipt $operation */
    private function execute(
        string $action,
        string $key,
        string $locale,
        string $payloadHash,
        LocalizationChangeContext $context,
        callable $operation,
    ): LocalizationOverrideReceipt {
        $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);

        $existing = $this->existingAuditReceipt($action, $context->requestFingerprint, $payloadHash, $key, $locale);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($action, $key, $locale, $payloadHash, $context, $operation): LocalizationOverrideReceipt {
                $this->authorizeInsideTransaction($connection, $context->actorAdministratorId);
                $existing = $this->existingAuditReceipt($action, $context->requestFingerprint, $payloadHash, $key, $locale, true);
                if ($existing !== null) {
                    return $existing;
                }

                return $operation($connection);
            });
        } catch (QueryException $exception) {
            $existing = $this->existingAuditReceipt($action, $context->requestFingerprint, $payloadHash, $key, $locale);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function authorizeRead(int $administratorId): void
    {
        if ($administratorId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    private function authorizeInsideTransaction(Connection $connection, int $administratorId): void
    {
        /** @var object{status:string}|null $administrator */
        $administrator = $connection->table('administrators')->where('id', $administratorId)->lockForUpdate()->first(['status']);
        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    /** @return object{id:int|string,override_value:?string,version:int|string}|null */
    private function lockCurrent(Connection $connection, string $key, string $locale): ?object
    {
        /** @var object{id:int|string,override_value:?string,version:int|string}|null $row */
        $row = $connection->table('localization_overrides')
            ->where('translation_key', $key)
            ->where('locale', $locale)
            ->lockForUpdate()
            ->first(['id', 'override_value', 'version']);

        return $row;
    }

    private function assertExpectedVersion(int $currentVersion, ?int $expectedVersion): void
    {
        if ($expectedVersion === null || $currentVersion !== $expectedVersion) {
            throw new RuntimeException('Localization override version conflict.');
        }
    }

    private function recordVersion(
        Connection $connection,
        int $overrideId,
        int $version,
        string $action,
        ?string $value,
        LocalizationChangeContext $context,
        string $createdAt,
    ): void {
        $connection->table('localization_override_versions')->insert([
            'localization_override_id' => $overrideId,
            'version' => $version,
            'action' => $action,
            'override_value' => $value,
            'actor_administrator_id' => $context->actorAdministratorId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $createdAt,
        ]);
    }

    private function recordAudit(
        Connection $connection,
        LocalizationOverrideReceipt $receipt,
        LocalizationChangeContext $context,
        string $payloadHash,
        ?string $beforeValue,
        ?string $afterValue,
    ): void {
        $before = [
            'translation_key' => $receipt->translationKey,
            'locale' => $receipt->locale,
            'override_sha256' => $beforeValue === null ? null : hash('sha256', $beforeValue),
        ];
        $after = [
            'translation_key' => $receipt->translationKey,
            'locale' => $receipt->locale,
            'version' => $receipt->version,
            'override_sha256' => $afterValue === null ? null : hash('sha256', $afterValue),
            'changed' => $receipt->changed,
            'request_payload_hash' => $payloadHash,
        ];

        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => $receipt->action,
            'target_type' => 'localization_override',
            'target_id' => (string) $receipt->overrideId,
            'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function existingAuditReceipt(
        string $action,
        string $requestFingerprint,
        string $payloadHash,
        string $key,
        string $locale,
        bool $lock = false,
    ): ?LocalizationOverrideReceipt {
        $query = $this->database->connection()->table('audit_logs')
            ->where('action', $action)
            ->where('request_fingerprint', $requestFingerprint);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{target_id:?string,after_safe_data:?string}|null $row */
        $row = $query->first(['target_id', 'after_safe_data']);
        if ($row === null) {
            return null;
        }
        if ($row->target_id === null || ! ctype_digit($row->target_id) || $row->after_safe_data === null) {
            throw new RuntimeException('Stored localization audit receipt is invalid.');
        }

        $after = json_decode($row->after_safe_data, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($after)
            || ($after['translation_key'] ?? null) !== $key
            || ($after['locale'] ?? null) !== $locale
            || ($after['request_payload_hash'] ?? null) !== $payloadHash
            || ! is_int($after['version'] ?? null)
            || ! is_bool($after['changed'] ?? null)
        ) {
            throw new RuntimeException('Localization mutation fingerprint conflict.');
        }

        return new LocalizationOverrideReceipt(
            $action,
            (int) $row->target_id,
            $key,
            $locale,
            $after['version'],
            $after['changed'],
            true,
        );
    }

    private function payloadHash(
        string $key,
        string $locale,
        ?string $value,
        ?int $expectedVersion,
        ?int $sourceVersion,
    ): string {
        return hash('sha256', json_encode([
            'translation_key' => $key,
            'locale' => $locale,
            'value_sha256' => $value === null ? null : hash('sha256', $value),
            'expected_version' => $expectedVersion,
            'source_version' => $sourceVersion,
        ], JSON_THROW_ON_ERROR));
    }
}
