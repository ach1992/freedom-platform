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

    /**
     * @param  list<string>  $authorityTables
     *
     * @phpstan-impure
     */
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
            $grant = preg_replace('/\s+/', ' ', trim($grant));
            if (! is_string($grant) || $grant === '') {
                return false;
            }

            $privileges = $this->allowedGlobalGrantPrivileges($grant);
            if ($privileges === null) {
                return false;
            }

            foreach (array_map('trim', explode(',', $privileges)) as $privilege) {
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

    private function allowedGlobalGrantPrivileges(string $grant): ?string
    {
        $quotedIdentifier = '`(?:``|[^`])*`';
        $quotedString = "'(?:''|\\\\.|[^'])*'";
        $unquotedAccountAtom = '[A-Za-z0-9_.%:$-]+';
        $accountAtom = '(?:'.$quotedIdentifier.'|'.$quotedString.'|'.$unquotedAccountAtom.')';
        $account = $accountAtom.'@'.$accountAtom;

        $plugin = '(?:'.$quotedIdentifier.'|[A-Za-z0-9_.$-]+)';
        $pluginAuth = $plugin.'(?:\s+(?:USING|AS)\s+'.$quotedString.')?';
        $identifiedVia = $pluginAuth.'(?:\s+OR\s+'.$pluginAuth.')*';
        $authentication = '(?:\s+IDENTIFIED\s+(?:BY\s+PASSWORD\s+'.$quotedString.'|(?:VIA|WITH)\s+'.$identifiedVia.'))?';

        $tlsItem = '(?:SSL|X509|CIPHER\s+'.$quotedString.'|ISSUER\s+'.$quotedString.'|SUBJECT\s+'.$quotedString.')';
        $tls = '(?:\s+REQUIRE\s+(?:NONE|'.$tlsItem.'(?:\s+AND\s+'.$tlsItem.')*))?';

        $resourceName = '(?:MAX_QUERIES_PER_HOUR|MAX_UPDATES_PER_HOUR|MAX_CONNECTIONS_PER_HOUR|MAX_USER_CONNECTIONS|MAX_STATEMENT_TIME)';
        $resourceValue = '(?:[0-9]+(?:\.[0-9]+)?)';
        $resources = '(?:\s+WITH\s+'.$resourceName.'\s+'.$resourceValue.'(?:\s+'.$resourceName.'\s+'.$resourceValue.')*)?';

        $pattern = '~\AGRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s+'
            .$account
            .$authentication
            .$tls
            .$resources
            .'\z~iD';

        if (preg_match($pattern, $grant, $matches) !== 1) {
            return null;
        }

        return $matches[1];
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
