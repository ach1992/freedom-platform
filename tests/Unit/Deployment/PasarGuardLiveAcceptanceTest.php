<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use DateTimeImmutable;
use DateTimeZone;
use FreedomPlatform\Scripts\Ci\PasarGuardLiveAcceptance;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PasarGuardLiveAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/scripts/ci/PasarGuardLiveAcceptance.php';
    }

    public function test_happy_path_uses_one_create_and_cleans_up_without_disclosing_secrets(): void
    {
        $state = null;
        $createCount = 0;
        $deleteCount = 0;
        $transport = function (string $method, string $url, array $headers, ?array $payload) use (&$state, &$createCount, &$deleteCount): PanelHttpExchange {
            self::assertSame('pg_key_11111111-1111-1111-1111-111111111111', $headers['X-Api-Key']);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_starts_with($path, '/hpanel')) {
                $path = substr($path, strlen('/hpanel'));
            }

            if ($method === 'GET' && $path === '/api/system') {
                return new PanelHttpExchange(200, ['version' => '5.2.1'], false, false);
            }
            if ($method === 'GET' && $path === '/api/inbounds/details') {
                return new PanelHttpExchange(200, [['tag' => 'vless-main', 'protocol' => 'vless']], false, false);
            }
            if ($method === 'GET' && $path === '/api/groups') {
                return new PanelHttpExchange(200, ['groups' => [['id' => 7, 'name' => 'test', 'is_disabled' => false]], 'total' => 1], false, false);
            }
            if ($method === 'GET' && str_starts_with($path, '/api/user/by-username/')) {
                return $state === null
                    ? new PanelHttpExchange(404, ['detail' => 'not found'], false, false)
                    : new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'GET' && $path === '/api/user/by-id/77') {
                return $state === null
                    ? new PanelHttpExchange(404, ['detail' => 'not found'], false, false)
                    : new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && $path === '/api/user') {
                $createCount++;
                self::assertNotNull($payload);
                $state = [
                    'id' => 77,
                    'username' => $payload['username'],
                    'status' => 'active',
                    'data_limit' => $payload['data_limit'],
                    'expire' => $payload['expire'],
                    'data_limit_reset_strategy' => $payload['data_limit_reset_strategy'],
                    'group_ids' => $payload['group_ids'],
                    'used_traffic' => 123,
                    'proxy_settings' => [],
                    'hwid_limit' => null,
                    'subscription_url' => 'https://subscription.example/one',
                ];

                return new PanelHttpExchange(201, $state, false, false);
            }
            if ($method === 'PUT' && $path === '/api/user/by-id/77') {
                self::assertNotNull($state);
                self::assertNotNull($payload);
                $state = array_replace($state, $payload);

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'PUT' && $path === '/api/user/by-id/77/disabled') {
                self::assertNotNull($state);
                self::assertNotNull($payload);
                $state['status'] = $payload['disabled'] === true ? 'disabled' : 'active';

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && $path === '/api/user/by-id/77/reset') {
                self::assertNotNull($state);
                $state['used_traffic'] = 0;

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'POST' && $path === '/api/user/by-id/77/revoke_sub') {
                self::assertNotNull($state);
                $state['subscription_url'] = 'https://subscription.example/two';

                return new PanelHttpExchange(200, $state, false, false);
            }
            if ($method === 'DELETE' && $path === '/api/user/by-id/77') {
                $deleteCount++;
                $state = null;

                return new PanelHttpExchange(204, [], false, false);
            }

            return new PanelHttpExchange(404, ['detail' => 'unexpected'], false, false);
        };

        $runner = new PasarGuardLiveAcceptance($transport);
        $summary = $runner->run([
            'origin' => 'https://panel.example/hpanel',
            'api_key' => 'pg_key_11111111-1111-1111-1111-111111111111',
            'run_id' => 'unit-123',
            'confirm' => PasarGuardLiveAcceptance::CONFIRMATION,
            'group_id' => 7,
            'now' => new DateTimeImmutable('2026-08-08T00:00:00+00:00', new DateTimeZone('UTC')),
        ]);

        self::assertSame(1, $createCount);
        self::assertSame(1, $deleteCount);
        self::assertTrue($summary['cleanup_verified']);
        self::assertSame('5.2.1', $summary['version']);
        self::assertSame('/hpanel', $summary['base_path']);
        self::assertSame(7, $summary['group_id']);
        self::assertContains('LIVE-006', $summary['executed_rows']);
        self::assertContains('LIVE-018', $summary['executed_rows']);
        self::assertContains('LIVE-023', $summary['executed_rows']);
        self::assertSame(['LIVE-009', 'LIVE-010', 'LIVE-019', 'LIVE-020', 'LIVE-021', 'LIVE-024'], $summary['deferred_rows']);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('pg_key_', $encoded);
        self::assertStringNotContainsString('subscription.example', $encoded);
        self::assertStringNotContainsString('unit-123', $encoded);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $summary['test_user_sha256']);
    }

    public function test_version_mismatch_stops_before_any_mutation(): void
    {
        $mutationCount = 0;
        $transport = static function (string $method, string $url, array $headers, ?array $payload) use (&$mutationCount): PanelHttpExchange {
            if ($method !== 'GET') {
                $mutationCount++;
            }

            return new PanelHttpExchange(200, ['version' => '5.2.2'], false, false);
        };

        $runner = new PasarGuardLiveAcceptance($transport);

        try {
            $runner->run([
                'origin' => 'https://panel.example',
                'api_key' => 'pg_key_11111111-1111-1111-1111-111111111111',
                'run_id' => 'unit-version',
                'confirm' => PasarGuardLiveAcceptance::CONFIRMATION,
                'now' => new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
            ]);
            self::fail('Expected version mismatch.');
        } catch (RuntimeException $exception) {
            self::assertSame('pasarguard_live_version_mismatch', $exception->getMessage());
        }

        self::assertSame(0, $mutationCount);
    }
}
