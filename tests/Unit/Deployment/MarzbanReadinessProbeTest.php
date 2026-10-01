<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use App\Modules\Panels\Infrastructure\MarzbanMutationContractMapper;
use FreedomPlatform\Scripts\Ci\MarzbanReadinessProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MarzbanReadinessProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/scripts/ci/MarzbanReadinessProbe.php';
    }

    public function test_happy_path_authenticates_and_returns_only_sanitized_contract_evidence(): void
    {
        $calls = [];
        $transport = static function (
            string $method,
            string $url,
            array $headers,
            ?array $payload,
            bool $form,
        ) use (&$calls): array {
            $calls[] = [$method, $url, $headers, $payload, $form];
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($method === 'POST' && $path === '/panel/api/admin/token') {
                self::assertTrue($form);
                self::assertSame('admin-user', $payload['username'] ?? null);
                self::assertSame('super-secret-password', $payload['password'] ?? null);

                return self::exchange(200, ['access_token' => 'safe-bearer-token']);
            }

            self::assertSame('Bearer safe-bearer-token', $headers['Authorization'] ?? null);
            if ($method === 'GET' && $path === '/panel/api/system') {
                return self::exchange(200, ['version' => 'v0.8.4']);
            }
            if ($method === 'GET' && $path === '/panel/api/inbounds') {
                return self::exchange(200, [
                    'vless' => [['tag' => 'vless-main']],
                    'vmess' => [['tag' => 'vmess-main'], ['tag' => 'vmess-backup']],
                ]);
            }

            return self::exchange(404, ['detail' => 'unexpected']);
        };

        $summary = (new MarzbanReadinessProbe($transport))->run([
            'origin' => 'https://panel.example/panel',
            'username' => 'admin-user',
            'password' => 'super-secret-password',
        ]);

        self::assertSame(MarzbanMutationContractMapper::VERSION, MarzbanReadinessProbe::EXPECTED_VERSION);
        self::assertSame('marzban', $summary['provider']);
        self::assertSame('0.8.4', $summary['version']);
        self::assertSame('/panel', $summary['base_path']);
        self::assertSame('username_password_bearer', $summary['authentication']);
        self::assertSame(3, $summary['inbound_count']);
        self::assertSame(2, $summary['protocol_count']);
        self::assertSame(['vless', 'vmess'], $summary['protocols']);
        self::assertCount(3, $calls);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('admin-user', $encoded);
        self::assertStringNotContainsString('super-secret-password', $encoded);
        self::assertStringNotContainsString('safe-bearer-token', $encoded);
        self::assertStringNotContainsString('panel.example', $encoded);
    }

    public function test_version_mismatch_stops_before_inbound_discovery(): void
    {
        $inboundCalls = 0;
        $transport = static function (
            string $method,
            string $url,
            array $headers,
            ?array $payload,
            bool $form,
        ) use (&$inboundCalls): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($method === 'POST' && $path === '/api/admin/token') {
                return self::exchange(200, ['access_token' => 'safe-bearer-token']);
            }
            if ($method === 'GET' && $path === '/api/system') {
                return self::exchange(200, ['version' => '0.8.5']);
            }
            if ($path === '/api/inbounds') {
                $inboundCalls++;
            }

            return self::exchange(200, []);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('marzban_readiness_version_mismatch');

        try {
            (new MarzbanReadinessProbe($transport))->run([
                'origin' => 'https://panel.example',
                'username' => 'admin-user',
                'password' => 'super-secret-password',
            ]);
        } finally {
            self::assertSame(0, $inboundCalls);
        }
    }

    public function test_transport_failure_is_reported_without_secret_context(): void
    {
        $probe = new MarzbanReadinessProbe(static fn (
            string $method,
            string $url,
            array $headers,
            ?array $payload,
            bool $form,
        ): array => [
            'status' => null,
            'json' => null,
            'malformed' => false,
            'transport_failure' => true,
        ]);

        try {
            $probe->run([
                'origin' => 'https://panel.example',
                'username' => 'admin-user',
                'password' => 'super-secret-password',
            ]);
            self::fail('Expected a transport failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('marzban_readiness_authentication_transport_failure', $exception->getMessage());
            self::assertStringNotContainsString('admin-user', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-password', $exception->getMessage());
        }
    }

    /** @param array<array-key, mixed> $json */
    private static function exchange(int $status, array $json): array
    {
        return [
            'status' => $status,
            'json' => $json,
            'malformed' => false,
            'transport_failure' => false,
        ];
    }
}
