<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramDeliveryLifecycleDatabaseAuthorityTest extends TestCase
{
    public function test_exact_lifecycle_grant_set_is_accepted(): void
    {
        $authority = new TelegramDeliveryLifecycleDatabaseAuthority;

        self::assertTrue($authority->grantSetIsSelectUpdateOnly([
            "GRANT USAGE ON *.* TO `telegram_lifecycle`@`%` IDENTIFIED BY PASSWORD '*HASH'",
            'GRANT SELECT, UPDATE ON `freedom_platform_ci`.* TO `telegram_lifecycle`@`%`',
        ], 'freedom_platform_ci'));
    }

    #[DataProvider('rejectedGrantSets')]
    public function test_lifecycle_grant_set_fails_closed(array $grants): void
    {
        self::assertFalse((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetIsSelectUpdateOnly(
            $grants,
            'freedom_platform_ci',
        ));
    }

    public function test_reference_fence_grants_accept_exact_database_wildcard_escaping(): void
    {
        self::assertTrue((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetCanUseReferenceFenceLocks([
            'GRANT ALL PRIVILEGES ON `freedom\\_platform\\_ci`.* TO `freedom_ci`@`%`',
        ], 'freedom_platform_ci'));
    }

    public function test_reference_fence_grants_accept_split_direct_privileges(): void
    {
        self::assertTrue((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetCanUseReferenceFenceLocks([
            'GRANT SELECT ON `freedom_platform_ci`.* TO `freedom_ci`@`%`',
            'GRANT LOCK TABLES ON `freedom_platform_ci`.* TO `freedom_ci`@`%`',
        ], 'freedom_platform_ci'));
    }

    public function test_reference_fence_grants_accept_complete_per_table_privileges(): void
    {
        self::assertTrue((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetCanUseReferenceFenceLocks([
            'GRANT SELECT, LOCK TABLES ON `freedom_platform_ci`.`telegram_delivery_operations` TO `freedom_ci`@`%`',
            'GRANT SELECT, LOCK TABLES ON `freedom_platform_ci`.`telegram_delivery_authority_capability` TO `freedom_ci`@`%`',
        ], 'freedom_platform_ci'));
    }

    #[DataProvider('rejectedReferenceFenceGrantSets')]
    public function test_reference_fence_grants_fail_closed(array $grants): void
    {
        self::assertFalse((new TelegramDeliveryLifecycleDatabaseAuthority)->grantSetCanUseReferenceFenceLocks(
            $grants,
            'freedom_platform_ci',
        ));
    }

    /** @return iterable<string,array{0:list<string>}> */
    public static function rejectedGrantSets(): iterable
    {
        $usage = "GRANT USAGE ON *.* TO `telegram_lifecycle`@`%` IDENTIFIED BY PASSWORD '*HASH'";

        yield 'missing select' => [[$usage, 'GRANT UPDATE ON `freedom_platform_ci`.* TO `telegram_lifecycle`@`%`']];
        yield 'missing update' => [[$usage, 'GRANT SELECT ON `freedom_platform_ci`.* TO `telegram_lifecycle`@`%`']];
        yield 'extra delete' => [[$usage, 'GRANT SELECT, UPDATE, DELETE ON `freedom_platform_ci`.* TO `telegram_lifecycle`@`%`']];
        yield 'wrong schema' => [[$usage, 'GRANT SELECT, UPDATE ON `other_database`.* TO `telegram_lifecycle`@`%`']];
        yield 'table scoped' => [[$usage, 'GRANT SELECT, UPDATE ON `freedom_platform_ci`.`telegram_delivery_authority_capability` TO `telegram_lifecycle`@`%`']];
        yield 'grant option' => [[$usage, 'GRANT SELECT, UPDATE ON `freedom_platform_ci`.* TO `telegram_lifecycle`@`%` WITH GRANT OPTION']];
        yield 'role assignment' => [[$usage, 'GRANT `telegram_lifecycle_role` TO `telegram_lifecycle`@`%`']];
        yield 'public authority' => [[$usage, 'GRANT SELECT, UPDATE ON `freedom_platform_ci`.* TO PUBLIC']];
        yield 'wrong user' => [['GRANT USAGE ON *.* TO `other_user`@`%`', 'GRANT SELECT, UPDATE ON `freedom_platform_ci`.* TO `other_user`@`%`']];
        yield 'proxy' => [[$usage, 'GRANT PROXY ON `root`@`localhost` TO `telegram_lifecycle`@`%`']];
        yield 'empty' => [[]];
    }

    /** @return iterable<string,array{0:list<string>}> */
    public static function rejectedReferenceFenceGrantSets(): iterable
    {
        yield 'missing lock tables' => [[
            'GRANT SELECT, ALTER, DROP, INDEX ON `freedom_platform_ci`.* TO `freedom_ci`@`%`',
        ]];
        yield 'missing select' => [[
            'GRANT LOCK TABLES ON `freedom_platform_ci`.* TO `freedom_ci`@`%`',
        ]];
        yield 'wrong schema' => [[
            'GRANT SELECT, LOCK TABLES ON `other_database`.* TO `freedom_ci`@`%`',
        ]];
        yield 'only one authority table covered' => [[
            'GRANT SELECT, LOCK TABLES ON `freedom_platform_ci`.`telegram_delivery_operations` TO `freedom_ci`@`%`',
        ]];
        yield 'column select is not complete table select' => [[
            'GRANT SELECT (`id`), LOCK TABLES ON `freedom_platform_ci`.`telegram_delivery_operations` TO `freedom_ci`@`%`',
            'GRANT SELECT, LOCK TABLES ON `freedom_platform_ci`.`telegram_delivery_authority_capability` TO `freedom_ci`@`%`',
        ]];
        yield 'role-only authority is not accepted as direct evidence' => [[
            'GRANT `rollback_role` TO `freedom_ci`@`%`',
        ]];
        yield 'public authority is not accepted for destructive ddl' => [[
            'GRANT SELECT, LOCK TABLES ON `freedom_platform_ci`.* TO PUBLIC',
        ]];
        yield 'empty' => [[]];
    }
}
