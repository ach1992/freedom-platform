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

    public function __construct(private ?DatabaseManager $database = null) {}

    public function requireConnection(Connection $runtimeConnection): Connection
    {
        $connection = $this->validatedConnection($runtimeConnection);
        if (! $connection instanceof Connection) {
            throw new RuntimeException('Telegram delivery lifecycle authority requires the exact dedicated SELECT/UPDATE-only MariaDB principal.');
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

        $expectedQuotedObject = '`'.str_replace('`', '``', $databaseName).'`.*';
        $expectedPlainObject = $databaseName.'.*';
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
                && ! hash_equals($expectedPlainObject, $object)) {
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
