<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServiceReconfigurationQuoteMigrationContractTest extends TestCase
{
    public function test_quote_guard_assets_atomically_replace_the_existing_guard(): void
    {
        $root = dirname(__DIR__, 5);

        foreach (['quote-insert-guard-v1.sql', 'quote-insert-guard-v2.sql'] as $file) {
            $source = file_get_contents(
                $root.'/database/sql/service-reconfiguration-quote-authority/'.$file,
            );
            if (! is_string($source)) {
                throw new RuntimeException('Service reconfiguration Quote guard asset is unavailable.');
            }

            self::assertMatchesRegularExpression(
                '/\ACREATE OR REPLACE TRIGGER quotes_insert_guard\b/',
                $source,
            );
            self::assertStringNotContainsString(
                "\nCREATE TRIGGER quotes_insert_guard",
                "\n".$source,
            );
        }
    }
}
