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

    public function __construct(private ?DatabaseManager $database = null) {}

    /** @param list<string> $authorityTables */
    public function matchesExpected(Connection $runtimeConnection, array $authorityTables): bool
    {
        if ($runtimeConnection->getDriverName() !== 'mysql' || $authorityTables === []) {
            return false;
        }

        try {
            if (! $this->runtimePrincipalIsProcessFree($runtimeConnection)) {
                return false;
            }

            $metadataConnection = $this->database()->connection(self::METADATA_CONNECTION);
            if ($metadataConnection->getDriverName() !== 'mysql'
                || ! $this->sameMariaDbServer($runtimeConnection, $metadataConnection)
                || ! $this->usesDistinctPrincipal($runtimeConnection, $metadataConnection)
                || ! $this->metadataPrincipalIsProcessOnly($metadataConnection)) {
                return false;
            }

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

        $sawProcess = false;
        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if (count($values) !== 1 || ! is_string($values[0])) {
                return false;
            }

            $grant = strtoupper(trim($values[0]));
            if (str_contains($grant, 'WITH GRANT OPTION')) {
                return false;
            }

            if (preg_match('/\AGRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s+/', $grant, $matches) !== 1) {
                return false;
            }

            foreach (array_map('trim', explode(',', $matches[1])) as $privilege) {
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

        return $runtime !== null && $runtime === $metadata;
    }

    /** @return array{uid:?string,hostname:string,port:int,server_id:int,version:string}|null */
    private function serverIdentity(Connection $connection): ?array
    {
        $identity = $connection->selectOne(<<<'SQL'
SELECT
    @@hostname AS hostname,
    @@port AS port,
    @@server_id AS server_id,
    VERSION() AS version
SQL, [], false);
        if ($identity === null) {
            return null;
        }

        $uidRow = $connection->selectOne("SHOW VARIABLES LIKE 'server_uid'", [], false);
        $uid = $uidRow === null ? null : trim((string) ($uidRow->Value ?? ''));
        if ($uid === '') {
            $uid = null;
        }

        return [
            'uid' => $uid,
            'hostname' => (string) ($identity->hostname ?? ''),
            'port' => (int) ($identity->port ?? 0),
            'server_id' => (int) ($identity->server_id ?? 0),
            'version' => (string) ($identity->version ?? ''),
        ];
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? app(DatabaseManager::class);
    }
}
