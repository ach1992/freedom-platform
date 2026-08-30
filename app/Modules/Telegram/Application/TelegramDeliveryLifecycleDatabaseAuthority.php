<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Resolves the deployment-only MariaDB principal permitted to change the
 * Telegram delivery capability lifecycle state. GET_LOCK serializes migration
 * runners; this principal is the independent database authorization boundary.
 */
final readonly class TelegramDeliveryLifecycleDatabaseAuthority
{
    private const CONNECTION = 'telegram_lifecycle';

    private const MINIMUM_SERVER_UID_VERSION = '10.11.9';

    private const REQUIRED_USERNAME = 'telegram_lifecycle';

    /** @var list<string> */
    private const REFERENCE_FENCE_TABLES = [
        'telegram_delivery_operations',
        'telegram_delivery_authority_capability',
    ];

    public function __construct(private ?DatabaseManager $database = null) {}

    public function requireConnection(Connection $runtimeConnection): Connection
    {
        $connection = $this->validatedConnection($runtimeConnection);
        if (! $connection instanceof Connection) {
            throw new RuntimeException('Telegram delivery lifecycle authority requires the exact dedicated SELECT/UPDATE-only MariaDB principal.');
        }

        // The migration calls requireConnection() once before acquiring GET_LOCK
        // and again from rollbackMysql() while this exact lifecycle session owns
        // it. Only the latter is the irreversible rollback path, so keep normal
        // install/repair/no-op migration semantics unchanged while mechanically
        // attesting the DDL principal before an active capability can deactivate.
        if ($this->ownsInstallationLock($connection)) {
            $this->assertRuntimeReferenceFencePrivilegesIfActive($runtimeConnection, $connection);
        }

        return $connection;
    }

    public function connectionBoundaryMatchesExpected(Connection $runtimeConnection): bool
    {
        return $this->validatedConnection($runtimeConnection) instanceof Connection;
    }

    public function principalUsername(Connection $connection): string
    {
        $username = $this->connectionUsername($connection);
        if ($username === null) {
            throw new RuntimeException('Telegram delivery lifecycle database principal identity is unavailable.');
        }

        return $username;
    }

    private function validatedConnection(Connection $runtimeConnection): ?Connection
    {
        if ($runtimeConnection->getDriverName() !== 'mysql') {
            return null;
        }

        try {
            $lifecycleConnection = $this->database()->connection(self::CONNECTION);
            if ($lifecycleConnection->getDriverName() !== 'mysql') {
                return null;
            }

            $runtimeDatabase = $runtimeConnection->getDatabaseName();
            if ($runtimeDatabase === '' || ! hash_equals($runtimeDatabase, $lifecycleConnection->getDatabaseName())) {
                return null;
            }

            if (! $this->sameMariaDbServer($runtimeConnection, $lifecycleConnection)) {
                return null;
            }

            $runtimeUsername = $this->connectionUsername($runtimeConnection);
            $lifecycleUsername = $this->connectionUsername($lifecycleConnection);
            $metadataConnection = $this->database()->connection('telegram_metadata');
            $metadataUsername = $this->connectionUsername($metadataConnection);
            if ($runtimeUsername === null
                || $lifecycleUsername === null
                || $metadataUsername === null
                || ! hash_equals(self::REQUIRED_USERNAME, $lifecycleUsername)
                || hash_equals($runtimeUsername, $lifecycleUsername)
                || hash_equals($metadataUsername, $lifecycleUsername)) {
                return null;
            }

            if (! $this->sameMariaDbServer($runtimeConnection, $metadataConnection)
                || ! $this->lifecyclePrincipalIsSelectUpdateOnly($lifecycleConnection, $runtimeDatabase)) {
                return null;
            }

            return $lifecycleConnection;
        } catch (Throwable) {
            return null;
        }
    }

    private function ownsInstallationLock(Connection $lifecycleConnection): bool
    {
        $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($lifecycleConnection);
        $ownership = $lifecycleConnection->selectOne(
            'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(?) AS lock_owner',
            [$lockName],
            false,
        );
        if ($ownership === null) {
            throw new RuntimeException('Telegram delivery lifecycle authority could not attest installation-lock ownership.');
        }

        $connectionId = (int) ($ownership->connection_id ?? 0);
        $lockOwner = (int) ($ownership->lock_owner ?? 0);

        return $connectionId > 0 && $lockOwner === $connectionId;
    }

    private function assertRuntimeReferenceFencePrivilegesIfActive(
        Connection $runtimeConnection,
        Connection $lifecycleConnection,
    ): void {
        if (! $lifecycleConnection->getSchemaBuilder()->hasTable('telegram_delivery_authority_capability')) {
            return;
        }

        try {
            $capability = $lifecycleConnection->table('telegram_delivery_authority_capability')
                ->where('id', 1)
                ->first(['schema_version', 'activated_at']);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Telegram delivery rollback reference fence could not attest active lifecycle state before validating the exact DDL principal privileges.',
                0,
                $exception,
            );
        }

        if ($capability === null
            || (int) ($capability->schema_version ?? -1) !== 1
            || ($capability->activated_at ?? null) === null) {
            return;
        }

        try {
            $rows = $runtimeConnection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Telegram delivery rollback reference fence could not attest the exact DDL principal privileges before lifecycle deactivation.',
                0,
                $exception,
            );
        }

        $grants = [];
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if (count($values) !== 1 || ! is_string($values[0])) {
                throw new RuntimeException(
                    'Telegram delivery rollback reference fence received an invalid exact DDL principal grant set.',
                );
            }
            $grants[] = $values[0];
        }

        if (! $this->grantSetCanUseReferenceFenceLocks($grants, $runtimeConnection->getDatabaseName())) {
            throw new RuntimeException(
                'Telegram delivery rollback reference fence requires effective SELECT and LOCK TABLES authority on both authority tables before lifecycle deactivation.',
            );
        }
    }

    /** @param list<string> $grants */
    public function grantSetCanUseReferenceFenceLocks(array $grants, string $databaseName): bool
    {
        if ($grants === [] || $databaseName === '') {
            return false;
        }

        $coverage = [];
        foreach (self::REFERENCE_FENCE_TABLES as $table) {
            $coverage[$table] = ['select' => false, 'lock_tables' => false];
        }

        foreach ($grants as $grantValue) {
            if (! is_string($grantValue)) {
                return false;
            }

            $grant = preg_replace('/\s+/', ' ', trim($grantValue));
            if (! is_string($grant) || $grant === '') {
                return false;
            }

            // PUBLIC and role/default-role authority is deliberately not accepted
            // as proof for this destructive DDL boundary. If the exact migration
            // principal lacks a directly visible grant, rollback fails closed.
            if (preg_match('/\bTO\s+`?PUBLIC`?(?:\s|\z)/iD', $grant) === 1) {
                continue;
            }

            if (preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+.+\z/iD', $grant, $matches) !== 1) {
                continue;
            }

            $privilegeList = trim($matches[1]);
            $object = trim($matches[2]);
            if ($privilegeList === '' || $object === '' || str_contains($privilegeList, '(')) {
                continue;
            }

            $privileges = [];
            foreach (array_map('trim', explode(',', strtoupper($privilegeList))) as $privilege) {
                $privilege = preg_replace('/\s+/', ' ', $privilege);
                if (is_string($privilege) && $privilege !== '') {
                    $privileges[$privilege] = true;
                }
            }

            $all = isset($privileges['ALL PRIVILEGES']) || isset($privileges['ALL']);
            foreach (self::REFERENCE_FENCE_TABLES as $table) {
                if (! $this->grantObjectCoversReferenceFenceTable($object, $databaseName, $table)) {
                    continue;
                }

                if ($all || isset($privileges['SELECT'])) {
                    $coverage[$table]['select'] = true;
                }
                if ($all || isset($privileges['LOCK TABLES'])) {
                    $coverage[$table]['lock_tables'] = true;
                }
            }
        }

        foreach ($coverage as $privileges) {
            if (! $privileges['select'] || ! $privileges['lock_tables']) {
                return false;
            }
        }

        return true;
    }

    private function grantObjectCoversReferenceFenceTable(
        string $object,
        string $databaseName,
        string $table,
    ): bool {
        if ($object === '*.*') {
            return true;
        }

        $quotedDatabase = '`'.str_replace('`', '``', $databaseName).'`';
        $grantPatternDatabase = '`'.str_replace(
            ['\\', '%', '_', '`'],
            ['\\\\', '\\%', '\\_', '``'],
            $databaseName,
        ).'`';
        $quotedTable = '`'.str_replace('`', '``', $table).'`';
        $objects = [
            $quotedDatabase.'.*',
            $grantPatternDatabase.'.*',
            $quotedDatabase.'.'.$quotedTable,
            $grantPatternDatabase.'.'.$quotedTable,
        ];

        if (preg_match('/\A[A-Za-z0-9_.$-]+\z/D', $databaseName) === 1) {
            $objects[] = $databaseName.'.*';
            $objects[] = $databaseName.'.'.$table;
        }

        return in_array($object, array_values(array_unique($objects)), true);
    }

    private function exactDatabaseGrantObject(string $databaseName): string
    {
        return '`'.str_replace(
            ['\\', '%', '_', '`'],
            ['\\\\', '\\%', '\\_', '``'],
            $databaseName,
        ).'`.*';
    }

    private function lifecyclePrincipalIsSelectUpdateOnly(Connection $connection, string $databaseName): bool
    {
        $rows = $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        $grants = [];
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if (count($values) !== 1 || ! is_string($values[0])) {
                return false;
            }
            $grants[] = $values[0];
        }

        return $this->grantSetIsSelectUpdateOnly($grants, $databaseName);
    }

    /** @param list<string> $grants */
    public function grantSetIsSelectUpdateOnly(array $grants, string $databaseName): bool
    {
        if ($grants === [] || $databaseName === '') {
            return false;
        }

        $expectedQuotedObject = $this->exactDatabaseGrantObject($databaseName);
        $expectedPlainObject = strpbrk($databaseName, '\\%_') === false
            ? $databaseName.'.*'
            : null;
        $expectedTargetPattern = '/\bTO\s+`telegram_lifecycle`@`[^`]+`(?:\s|\z)/iD';
        $sawUsage = false;
        $sawSelect = false;
        $sawUpdate = false;

        foreach ($grants as $grantValue) {
            if (! is_string($grantValue)) {
                return false;
            }

            $grant = preg_replace('/\s+/', ' ', trim($grantValue));
            if (! is_string($grant)
                || $grant === ''
                || preg_match($expectedTargetPattern, $grant) !== 1
                || preg_match('/\bWITH\s+(?:GRANT|ADMIN)\s+OPTION\b/i', $grant) === 1) {
                return false;
            }

            if (preg_match('/\AGRANT\s+USAGE\s+ON\s+\*\.\*\s+TO\s+.+\z/iD', $grant) === 1) {
                $sawUsage = true;

                continue;
            }

            if (preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+.+\z/iD', $grant, $matches) !== 1) {
                return false;
            }

            $object = trim($matches[2]);
            if (! hash_equals($expectedQuotedObject, $object)
                && ($expectedPlainObject === null || ! hash_equals($expectedPlainObject, $object))) {
                return false;
            }

            foreach (array_map('trim', explode(',', strtoupper($matches[1]))) as $privilege) {
                if ($privilege === 'SELECT') {
                    $sawSelect = true;

                    continue;
                }

                if ($privilege === 'UPDATE') {
                    $sawUpdate = true;

                    continue;
                }

                return false;
            }
        }

        return $sawUsage && $sawSelect && $sawUpdate;
    }

    private function connectionUsername(Connection $connection): ?string
    {
        $identity = $connection->selectOne(
            'SELECT USER() AS session_principal, CURRENT_USER() AS authenticated_principal',
            [],
            false,
        );
        if ($identity === null) {
            return null;
        }

        $sessionUsername = $this->accountUsername((string) ($identity->session_principal ?? ''));
        $authenticatedUsername = $this->accountUsername((string) ($identity->authenticated_principal ?? ''));
        if ($sessionUsername === null
            || $authenticatedUsername === null
            || ! hash_equals($sessionUsername, $authenticatedUsername)) {
            return null;
        }

        return $sessionUsername;
    }

    private function accountUsername(string $principal): ?string
    {
        $separator = strpos($principal, '@');
        if ($separator === false || $separator < 1) {
            return null;
        }

        $username = substr($principal, 0, $separator);
        if (preg_match('/\A[A-Za-z0-9_.$-]{1,80}\z/D', $username) !== 1) {
            return null;
        }

        return $username;
    }

    private function sameMariaDbServer(Connection $runtimeConnection, Connection $lifecycleConnection): bool
    {
        $runtime = $this->serverIdentity($runtimeConnection);
        $lifecycle = $this->serverIdentity($lifecycleConnection);

        return $runtime !== null
            && $lifecycle !== null
            && hash_equals($runtime, $lifecycle);
    }

    private function serverIdentity(Connection $connection): ?string
    {
        $identity = $connection->selectOne(
            'SELECT VERSION() AS version, @@server_uid AS server_uid',
            [],
            false,
        );
        if ($identity === null) {
            return null;
        }

        $version = trim((string) ($identity->version ?? ''));
        $uid = trim((string) ($identity->server_uid ?? ''));
        if (! $this->mariaDbVersionSupportsServerUid($version) || $uid === '' || strlen($uid) > 128) {
            return null;
        }

        return $uid;
    }

    private function mariaDbVersionSupportsServerUid(string $version): bool
    {
        if (stripos($version, 'mariadb') === false
            || preg_match('/\b(\d+\.\d+\.\d+)\b/', $version, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[1], self::MINIMUM_SERVER_UID_VERSION, '>=');
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? app(DatabaseManager::class);
    }
}
