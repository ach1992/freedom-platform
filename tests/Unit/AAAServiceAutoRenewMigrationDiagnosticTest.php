<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Throwable;

/**
 * Temporary diagnostic probe. This branch is never intended for integration.
 */
final class AAAServiceAutoRenewMigrationDiagnosticTest extends TestCase
{
    public function test_fresh_migrations_report_the_first_failure_immediately(): void
    {
        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
        } catch (Throwable $exception) {
            fwrite(STDERR, "\nAUTO_RENEW_MIGRATION_DIAGNOSTIC\n".$exception."\n");
            exit(97);
        }

        self::assertTrue(true);
    }
}
