<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Uses a distinct PROCESS-only MariaDB principal to inspect the global InnoDB
 * foreign-key inventory. The ordinary application principal must remain unable
 * to read INNODB_SYS_FOREIGN so provider/runtime authority never inherits this
 * server-wide metadata privilege.
 */
final readonly class TelegramDeliveryForeignKeyMetadataAttestor
{
    private const METADATA_CONNECTION = 'telegram_metadata';

    private const PROCESS_PRIVILEGE_REQUIRED_ERROR = 1227;

    private const MINIMUM_SERVER_UID_VERSION = '10.11.9';

    public function __construct(private ?DatabaseManager $database = null) {}

    public function connectionBoundaryMatchesExpected(Connection $runtimeConnection): bool
    {
        return $this->validatedMetadataConnection($runtimeConnection) instanceof Connection;
    }

    /** @param list<string> $authorityTables */
    public function matchesExpected(Connection $runtimeConnection, array $authorityTables): bool
    {
        if ($authorityTables === []) {
            return false;
        }

        $metadataConnection = $this->validatedMetadataConnection($runtimeConnection);
        if (! $metadataConnection instanceof Connection) {
            return false;
        }

        try {
            $databaseName = $runtimeConnection->getDatabaseName();
            if ($databaseName === '' || str_contains($databaseName, '/')) {
                return false;
            }

            $qualifiedTables = array_map(
                static fn (string $table): string => $databaseName.'/'.$table,
                $authorityTables,
            );

            return ! $metadataConnection->table('information_schema.INNODB_SYS_FOREIGN')
                ->where(function ($query) use ($qualifiedTables): void {
                    $query->whereIn('FOR_NAME', $qualifiedTables)
                        ->orWhereIn('REF_NAME', $qualifiedTables);
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function validatedMetadataConnection(Connection $runtimeConnection): ?Connection
    {
        if ($runtimeConnection->getDriverName() !== 'mysql') {
            return null;
        }

        try {
            if (! $this->runtimePrincipalIsProcessFree($runtimeConnection)) {
                return null;
            }

            $metadataConnection = $this->database()->connection(self::METADATA_CONNECTION);
            if ($metadataConnection->getDriverName() !== 'mysql'
                || ! $this->sameMariaDbServer($runtimeConnection, $metadataConnection)
                || ! $this->usesDistinctPrincipal($runtimeConnection, $metadataConnection)
                || ! $this->metadataPrincipalIsProcessOnly($metadataConnection)) {
                return null;
            }

            return $metadataConnection;
        } catch (Throwable) {
            return null;
        }
    }

    private function runtimePrincipalIsProcessFree(Connection $connection): bool
    {
        try {
            $connection->selectOne(
                'SELECT ID FROM information_schema.INNODB_SYS_FOREIGN LIMIT 1',
                [],
                false,
            );

            return false;
        } catch (QueryException $exception) {
            return (int) ($exception->errorInfo[1] ?? 0) === self::PROCESS_PRIVILEGE_REQUIRED_ERROR;
        } catch (Throwable) {
            return false;
        }
    }

    private function usesDistinctPrincipal(Connection $runtimeConnection, Connection $metadataConnection): bool
    {
        $runtime = $runtimeConnection->selectOne('SELECT CURRENT_USER() AS principal', [], false);
        $metadata = $metadataConnection->selectOne('SELECT CURRENT_USER() AS principal', [], false);
        if ($runtime === null || $metadata === null) {
            return false;
        }

        $runtimePrincipal = (string) ($runtime->principal ?? '');
        $metadataPrincipal = (string) ($metadata->principal ?? '');

        return $runtimePrincipal !== ''
            && $metadataPrincipal !== ''
            && ! hash_equals($runtimePrincipal, $metadataPrincipal);
    }

    private function metadataPrincipalIsProcessOnly(Connection $connection): bool
    {
        $rows = $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
        if ($rows === []) {
            return false;
        }

        $grants = [];
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if (count($values) !== 1 || ! is_string($values[0])) {
                return false;
            }

            $grants[] = $values[0];
        }

        return $this->grantSetIsProcessOnly($grants);
    }

    /** @param list<string> $grants */
    private function grantSetIsProcessOnly(array $grants): bool
    {
        if ($grants === []) {
            return false;
        }

        $sawProcess = false;
        foreach ($grants as $grant) {
            $grant = trim($grant);
            if ($grant === ''
                || stripos($grant, 'WITH GRANT OPTION') !== false
                || stripos($grant, 'WITH ADMIN OPTION') !== false
                || preg_match('/\AGRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s+/i', $grant, $matches) !== 1) {
                return false;
            }

            foreach (array_map('trim', explode(',', $matches[1])) as $privilege) {
                $privilege = strtoupper(preg_replace('/\s+/', ' ', $privilege) ?? '');
                if ($privilege === 'PROCESS') {
                    $sawProcess = true;

                    continue;
                }

                if ($privilege !== 'USAGE') {
                    return false;
                }
            }
        }

        return $sawProcess;
    }

    private function sameMariaDbServer(Connection $runtimeConnection, Connection $metadataConnection): bool
    {
        $runtime = $this->serverIdentity($runtimeConnection);
        $metadata = $this->serverIdentity($metadataConnection);

        return $runtime !== null
            && $metadata !== null
            && hash_equals($runtime, $metadata);
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
