<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstallerAccessTokenStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/freedom-installer-token-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_token_is_randomly_issued_and_consumed_once(): void
    {
        $clock = $this->clockAt('2026-08-03T00:00:00+00:00');
        $store = new InstallerAccessTokenStore($clock, $this->randomGenerator(), $this->path);

        $token = $store->issue(30);

        self::assertSame(64, strlen($token));
        self::assertTrue($store->consume($token));
        self::assertFalse($store->consume($token));
    }

    public function test_expired_token_is_rejected(): void
    {
        $clock = $this->clockAt('2026-08-03T00:00:00+00:00');
        $store = new InstallerAccessTokenStore($clock, $this->randomGenerator(), $this->path);
        $token = $store->issue(5);
        $clock->time = new DateTimeImmutable('2026-08-03T00:06:00+00:00');

        self::assertFalse($store->consume($token));
    }

    public function test_invalid_ttl_is_rejected_by_the_store(): void
    {
        $store = new InstallerAccessTokenStore(
            $this->clockAt('2026-08-03T00:00:00+00:00'),
            $this->randomGenerator(),
            $this->path,
        );

        $this->expectException(InvalidArgumentException::class);

        $store->issue(0);
    }

    public function test_malformed_expiry_fails_closed(): void
    {
        file_put_contents($this->path, json_encode([
            'token_hash' => hash('sha256', 'token'),
            'expires_at' => 'not-a-date',
            'consumed_at' => null,
        ], JSON_THROW_ON_ERROR));
        $store = new InstallerAccessTokenStore(
            $this->clockAt('2026-08-03T00:00:00+00:00'),
            $this->randomGenerator(),
            $this->path,
        );

        self::assertFalse($store->consume('token'));
    }

    private function clockAt(string $time): MutableTestClock
    {
        return new MutableTestClock(new DateTimeImmutable($time, new DateTimeZone('UTC')));
    }

    private function randomGenerator(): RandomGenerator
    {
        return new class implements RandomGenerator
        {
            public function bytes(int $length): string
            {
                return str_repeat("\x01", $length);
            }

            public function integer(int $minimum, int $maximum): int
            {
                return $minimum;
            }
        };
    }
}

final class MutableTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
