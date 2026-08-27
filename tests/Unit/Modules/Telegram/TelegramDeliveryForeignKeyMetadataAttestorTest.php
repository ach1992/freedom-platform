<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramDeliveryForeignKeyMetadataAttestor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TelegramDeliveryForeignKeyMetadataAttestorTest extends TestCase
{
    public function test_process_only_grant_set_rejects_routine_role_public_and_grant_option_authority(): void
    {
        $attestor = new TelegramDeliveryForeignKeyMetadataAttestor;
        $method = new ReflectionMethod($attestor, 'grantSetIsProcessOnly');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($attestor, [
            "GRANT PROCESS ON *.* TO 'metadata'@'%'",
        ]));
        self::assertTrue($method->invoke($attestor, [
            "GRANT USAGE ON *.* TO 'metadata'@'%'",
            "GRANT PROCESS ON *.* TO 'metadata'@'%'",
        ]));
        self::assertTrue($method->invoke($attestor, [
            "GRANT USAGE ON *.* TO `metadata`@`%` IDENTIFIED BY PASSWORD '*0123456789ABCDEF'",
            'GRANT PROCESS ON *.* TO `metadata`@`%` REQUIRE SSL WITH MAX_USER_CONNECTIONS 2',
        ]));
        self::assertTrue($method->invoke($attestor, [
            "GRANT USAGE ON *.* TO 'metadata'@'%' IDENTIFIED VIA mysql_native_password USING '*0123456789ABCDEF'",
            "GRANT PROCESS ON *.* TO 'metadata'@'%'",
        ]));
        self::assertTrue($method->invoke($attestor, [
            "GRANT USAGE ON *.* TO 'metadata'@'%' IDENTIFIED WITH ed25519 AS 'hash-value'",
            "GRANT PROCESS ON *.* TO 'metadata'@'%'",
        ]));

        foreach ([
            ["GRANT PROCESS ON *.* TO 'metadata'@'%' WITH GRANT OPTION"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%'", "GRANT EXECUTE ON PROCEDURE `freedom_platform`.`dangerous` TO 'metadata'@'%'"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%'", "GRANT `metadata_role` TO 'metadata'@'%'"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%'", 'GRANT SELECT ON `freedom_platform`.* TO `PUBLIC`'],
            ["GRANT PROCESS, SELECT ON *.* TO 'metadata'@'%'"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%' FUTURE AUTHORITY"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%' IDENTIFIED BY PASSWORD '*0123456789ABCDEF' FUTURE AUTHORITY"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%' WITH MAX_USER_CONNECTIONS 2 FUTURE AUTHORITY"],
            ["GRANT PROCESS ON *.* TO 'metadata'@'%' WITH ADMIN OPTION"],
        ] as $grants) {
            self::assertFalse($method->invoke($attestor, $grants));
        }
    }

    public function test_server_uid_identity_requires_supported_mariadb_patch_level(): void
    {
        $attestor = new TelegramDeliveryForeignKeyMetadataAttestor;
        $method = new ReflectionMethod($attestor, 'mariaDbVersionSupportsServerUid');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($attestor, '10.11.8-MariaDB'));
        self::assertTrue($method->invoke($attestor, '10.11.9-MariaDB'));
        self::assertTrue($method->invoke($attestor, '10.11.14-MariaDB-ubu2204'));
        self::assertTrue($method->invoke($attestor, '11.4.8-MariaDB'));
        self::assertFalse($method->invoke($attestor, '8.0.39'));
        self::assertFalse($method->invoke($attestor, ''));
    }
}
