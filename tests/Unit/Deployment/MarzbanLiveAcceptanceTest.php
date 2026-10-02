<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use DateTimeImmutable;
use DateTimeZone;
use FreedomPlatform\Scripts\Ci\MarzbanLiveAcceptance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MarzbanLiveAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/scripts/ci/MarzbanLiveAcceptance.php';
    }

    public function test_happy_path_uses_one_create_and_cleans_up_without_disclosing_secrets(): void
    {
        $state = null;
        $createCount = 0;
        $deleteCount = 0;

        $transport = function (string $method, string $url, array $headers, ?array $payload, bool $form) use (&$state, &$createCount, &$deleteCount): PanelHttpExchange {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_starts_with($path, '/marzban')) {
                $path = substr($path, strlen('/marzban'));
            }

            if ($method === 'POST' && $path === '/api/admin/token') {
                self::assertTrue($form);
                self::assertSame('marzban-admin', $payload['username'] ?? null);
                self::assertSame('super-secret-password', $payload['password'] ?? null);

                return new PanelHttpExchange(200, ['access_token' => 'safe-bearer-token'], false, false);
            }

            self::assertFalse($form);
            self::assertSame('Bearer safe-bearer-token', $headers['Authorization'] ?? null);

            if ($method === 'GET' && $path === '/api/system') {
                return new PanelHttpExchange(200, ['version' => 'v0.8.4'], false, false);
            }
            if ($method === 'GET' && $path === '/api/inbounds') {
                return new PanelHttpExchange(200, [
                    'vless' => [['tag' => 'vless-main']],
                    'vmess' => [['tag' => 'vmess-main']],
                ], false, false);
            }
            if ($method === 'GET' && str_starts_with($path, '/api/user/')) {
                return $state === null
                    ? new PanelHttpExchange(404, ['detail' => 'not found'], false, false)
                    : new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && $path === '/api/user') {
                $createCount++;
                self::assertNotNull($payload);
                $state = [
                    'username' => $payload['username'],
                    'status' => 'active',
                    'proxies' => $payload['proxies'],
                    'inbounds' => $payload['inbounds'],
                    'expire' => $payload['expire'],
                    'data_limit' => $payload['data_limit'],
                    'data_limit_reset_strategy' => $payload['data_limit_reset_strategy'],
                    'used_traffic' => 123,
                    'subscription_url' => 'https://subscription.example/one',
                    'links' => ['vless://config-one'],
                ];

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'PUT' && str_starts_with($path, '/api/user/')) {
                self::assertNotNull($state);
                self::assertNotNull($payload);
                $state = array_replace($state, $payload);

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && str_ends_with($path, '/reset')) {
                self::assertNotNull($state);
                $state['used_traffic'] = 0;

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && str_ends_with($path, '/revoke_sub')) {
                self::assertNotNull($state);
                // Keep the subscription URL stable to cover same-second Marzban token generation.
                $state['links'] = ['vless://config-two'];

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'DELETE' && str_starts_with($path, '/api/user/')) {
                $deleteCount++;
                $state = null;

                return new PanelHttpExchange(200, [], false, false);
            }

            return new PanelHttpExchange(404, ['detail' => 'unexpected'], false, false);
        };

        $runner = new MarzbanLiveAcceptance($transport);
        $summary = $runner->run([
            'origin' => 'https://panel.example/marzban',
            'username' => 'marzban-admin',
            'password' => 'super-secret-password',
            'run_id' => 'unit-123',
            'confirm' => MarzbanLiveAcceptance::CONFIRMATION,
            'now' => new DateTimeImmutable('2026-08-08T00:00:00+00:00', new DateTimeZone('UTC')),
        ]);

        self::assertSame(1, $createCount);
        self::assertSame(1, $deleteCount);
        self::assertTrue($summary['cleanup_verified']);
        self::assertSame('0.8.4', $summary['version']);
        self::assertSame('/marzban', $summary['base_path']);
        self::assertSame('username_password_bearer', $summary['authentication']);
        self::assertContains($summary['target_protocol'], ['vless', 'vmess']);
        self::assertSame(2, $summary['inbound_count']);
        self::assertContains('LIVE-006', $summary['executed_rows']);
        self::assertContains('LIVE-018', $summary['executed_rows']);
        self::assertContains('LIVE-023', $summary['executed_rows']);
        self::assertSame(['LIVE-009', 'LIVE-010', 'LIVE-019', 'LIVE-020', 'LIVE-021', 'LIVE-024'], $summary['deferred_rows']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $summary['target_sha256']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $summary['test_user_sha256']);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('marzban-admin', $encoded);
        self::assertStringNotContainsString('super-secret-password', $encoded);
        self::assertStringNotContainsString('safe-bearer-token', $encoded);
        self::assertStringNotContainsString('panel.example', $encoded);
        self::assertStringNotContainsString('vless-main', $encoded);
        self::assertStringNotContainsString('vmess-main', $encoded);
        self::assertStringNotContainsString('subscription.example', $encoded);
        self::assertStringNotContainsString('unit-123', $encoded);
    }

    public function test_version_mismatch_stops_before_any_provider_mutation(): void
    {
        $mutationCount = 0;
        $transport = static function (string $method, string $url, array $headers, ?array $payload, bool $form) use (&$mutationCount): PanelHttpExchange {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($method === 'POST' && $path === '/api/admin/token') {
                return new PanelHttpExchange(200, ['access_token' => 'safe-bearer-token'], false, false);
            }
            if ($method !== 'GET' && str_starts_with($path, '/api/user')) {
                $mutationCount++;
            }
            if ($method === 'GET' && $path === '/api/system') {
                return new PanelHttpExchange(200, ['version' => '0.8.5'], false, false);
            }

            return new PanelHttpExchange(404, ['detail' => 'unexpected'], false, false);
        };

        $runner = new MarzbanLiveAcceptance($transport);

        try {
            $runner->run([
                'origin' => 'https://panel.example',
                'username' => 'marzban-admin',
                'password' => 'super-secret-password',
                'run_id' => 'unit-version',
                'confirm' => MarzbanLiveAcceptance::CONFIRMATION,
                'now' => new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
            ]);
            self::fail('Expected version mismatch.');
        } catch (RuntimeException $exception) {
            self::assertSame('marzban_live_version_mismatch', $exception->getMessage());
        }

        self::assertSame(0, $mutationCount);
    }

    public function test_uncertain_create_is_not_retried_when_cleanup_proves_no_remote_service(): void
    {
        $createCount = 0;
        $userLookupCount = 0;

        $transport = static function (
            string $method,
            string $url,
            array $headers,
            ?array $payload,
            bool $form,
        ) use (&$createCount, &$userLookupCount): PanelHttpExchange {
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($method === 'POST' && $path === '/api/admin/token') {
                return new PanelHttpExchange(200, ['access_token' => 'safe-bearer-token'], false, false);
            }
            if ($method === 'GET' && $path === '/api/system') {
                return new PanelHttpExchange(200, ['version' => '0.8.4'], false, false);
            }
            if ($method === 'GET' && $path === '/api/inbounds') {
                return new PanelHttpExchange(200, ['vless' => [['tag' => 'vless-main']]], false, false);
            }
            if ($method === 'GET' && str_starts_with($path, '/api/user/')) {
                $userLookupCount++;

                return new PanelHttpExchange(404, ['detail' => 'not found'], false, false);
            }
            if ($method === 'POST' && $path === '/api/user') {
                $createCount++;

                return new PanelHttpExchange(null, null, false, true);
            }

            return new PanelHttpExchange(404, ['detail' => 'unexpected'], false, false);
        };

        $runner = new MarzbanLiveAcceptance($transport);

        try {
            $runner->run([
                'origin' => 'https://panel.example',
                'username' => 'marzban-admin',
                'password' => 'super-secret-password',
                'run_id' => 'unit-uncertain-create',
                'confirm' => MarzbanLiveAcceptance::CONFIRMATION,
                'now' => new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
            ]);
            self::fail('Expected uncertain create outcome.');
        } catch (RuntimeException $exception) {
            self::assertSame('marzban_create_service_transport_uncertain', $exception->getMessage());
        }

        self::assertSame(1, $createCount);
        self::assertSame(2, $userLookupCount);
    }

    public function test_confirmation_mismatch_performs_no_request(): void
    {
        $requestCount = 0;
        $runner = new MarzbanLiveAcceptance(static function () use (&$requestCount): PanelHttpExchange {
            $requestCount++;

            return new PanelHttpExchange(500, [], false, false);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('marzban_live_confirmation_invalid');

        try {
            $runner->run([
                'origin' => 'https://panel.example',
                'username' => 'marzban-admin',
                'password' => 'super-secret-password',
                'run_id' => 'unit-confirm',
                'confirm' => 'WRONG',
            ]);
        } finally {
            self::assertSame(0, $requestCount);
        }
    }
}
