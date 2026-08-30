<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Proves that an active Telegram authority surface can use the exact InnoDB
 * table-lock primitive required by destructive rollback before lifecycle
 * deactivation is allowed to persist.
 */
final class TelegramDeliveryReferenceFenceLockPreflight
{
    /** @var list<string> */
    private const AUTHORITY_TABLES = [
        'telegram_delivery_operations',
        'telegram_delivery_authority_capability',
    ];

    public function assertActiveSurfaceLockCapability(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Telegram delivery rollback reference-fence lock preflight requires MariaDB/MySQL.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Telegram delivery rollback reference-fence lock preflight requires no active runtime transaction.');
        }

        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('telegram_delivery_authority_capability')) {
            return;
        }

        $capability = $connection->table('telegram_delivery_authority_capability')
            ->select(['schema_version', 'activated_at'])
            ->where('id', 1)
            ->first();
        if ($capability === null
            || (int) ($capability->schema_version ?? -1) !== 1
            || ($capability->activated_at ?? null) === null) {
            return;
        }

        foreach (self::AUTHORITY_TABLES as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException(
                    'Telegram delivery rollback reference-fence lock preflight found an incomplete active authority surface.',
                );
            }
        }

        $this->assertSessionAndTopologyPrerequisites($connection);
        $this->proveAuthorityTableWriteLocks($connection);
    }

    private function assertSessionAndTopologyPrerequisites(Connection $connection): void
    {
        $locking = $connection->selectOne(
            'SELECT @@SESSION.innodb_table_locks AS innodb_table_locks',
            [],
            false,
        );
        if ($locking === null || (int) ($locking->innodb_table_locks ?? -1) !== 1) {
            throw new RuntimeException(
                'Telegram delivery rollback reference fence requires @@SESSION.innodb_table_locks = 1.',
            );
        }

        $wsrepRows = $connection->select("SHOW SESSION VARIABLES LIKE 'wsrep_on'", [], false);
        if (count($wsrepRows) > 1) {
            throw new RuntimeException('Telegram delivery rollback reference fence found ambiguous Galera/wsrep state.');
        }
        if ($wsrepRows !== []) {
            $wsrepOn = strtoupper(trim((string) ($wsrepRows[0]->Value ?? '')));
            if (! in_array($wsrepOn, ['OFF', '0'], true)) {
                if (! in_array($wsrepOn, ['ON', '1'], true)) {
                    throw new RuntimeException('Telegram delivery rollback reference fence found an invalid Galera/wsrep state.');
                }

                throw new RuntimeException(
                    'Telegram delivery rollback reference fence is not supported while Galera/wsrep is enabled.',
                );
            }
        }

        $providerRows = $connection->select("SHOW GLOBAL VARIABLES LIKE 'wsrep_provider'", [], false);
        if (count($providerRows) > 1) {
            throw new RuntimeException('Telegram delivery rollback reference fence found ambiguous Galera provider state.');
        }
        if ($providerRows !== []) {
            $provider = strtolower(trim((string) ($providerRows[0]->Value ?? '')));
            if (! in_array($provider, ['', 'none'], true)) {
                throw new RuntimeException(
                    'Telegram delivery rollback reference fence is not supported with a loaded Galera provider.',
                );
            }
        }
    }

    private function proveAuthorityTableWriteLocks(Connection $connection): void
    {
        $autocommit = $connection->selectOne('SELECT @@SESSION.autocommit AS autocommit', [], false);
        if ($autocommit === null) {
            throw new RuntimeException('Telegram delivery rollback reference-fence lock preflight could not read autocommit state.');
        }

        $autocommitValue = (int) ($autocommit->autocommit ?? -1);
        if (! in_array($autocommitValue, [0, 1], true)) {
            throw new RuntimeException('Telegram delivery rollback reference-fence lock preflight found an invalid autocommit state.');
        }
        $restoreAutocommit = $autocommitValue === 1;

        try {
            if ($restoreAutocommit) {
                $connection->statement('SET autocommit = 0');
            }

            foreach (self::AUTHORITY_TABLES as $table) {
                $locked = false;
                try {
                    try {
                        $connection->statement(match ($table) {
                            'telegram_delivery_operations' => 'LOCK TABLES `telegram_delivery_operations` WRITE',
                            'telegram_delivery_authority_capability' => 'LOCK TABLES `telegram_delivery_authority_capability` WRITE',
                        });
                        $locked = true;
                    } catch (Throwable $exception) {
                        throw new RuntimeException(
                            'Telegram delivery rollback reference fence could not prove effective LOCK TABLES and SELECT authority before lifecycle deactivation.',
                            0,
                            $exception,
                        );
                    }
                } finally {
                    if ($locked) {
                        try {
                            $connection->statement('UNLOCK TABLES');
                        } catch (Throwable $exception) {
                            $this->disconnect($connection);
                            throw new RuntimeException(
                                'Telegram delivery rollback reference-fence lock preflight cleanup failed.',
                                0,
                                $exception,
                            );
                        }
                    }
                }
            }
        } finally {
            if ($restoreAutocommit) {
                try {
                    $connection->statement('SET autocommit = 1');
                } catch (Throwable $exception) {
                    $this->disconnect($connection);
                    throw new RuntimeException(
                        'Telegram delivery rollback reference-fence lock preflight autocommit restoration failed.',
                        0,
                        $exception,
                    );
                }
            }
        }
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }
}
