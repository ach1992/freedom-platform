<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\RestoreRuntimeConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RestoreRuntimeConfigurationTest extends TestCase
{
    /** @requirement BAK-002 OPS-003 */
    public function test_quiescence_must_be_positive_and_bounded(): void
    {
        $configuration = new RestoreRuntimeConfiguration(false, '/usr/bin/mariadb', 1800, 360);
        self::assertSame(360, $configuration->quiesceSeconds);

        foreach ([0, -1, 3601] as $seconds) {
            try {
                new RestoreRuntimeConfiguration(false, '/usr/bin/mariadb', 1800, $seconds);
                self::fail('Invalid restore quiescence must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Restore quiescence configuration is invalid.', $exception->getMessage());
            }
        }
    }
}
