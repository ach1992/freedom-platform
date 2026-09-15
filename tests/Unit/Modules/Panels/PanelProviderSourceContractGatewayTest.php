<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\MarzbanGatewayFactory;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelDnsResolver;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PasarGuardGatewayFactory;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\RemoteIdentityDisposition;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use App\Modules\Panels\Infrastructure\MarzbanSourceContractGatewayFactory;
use App\Modules\Panels\Infrastructure\PasarGuardSourceContractGatewayFactory;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-001 SEC-002 QUA-001 */
final class PanelProviderSourceContractGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PanelDnsResolver::class, new ProviderContractPanelDnsResolver);
    }

    public function test_service_provider_binds_pinned_read_only_gateway_factories(): void
    {
        self::assertInstanceOf(
            MarzbanSourceContractGatewayFactory::class,
            $this->app->make(MarzbanGatewayFactory::class),
        );
        self::assertInstanceOf(
            PasarGuardSourceContractGatewayFactory::class,
            $this->app->make(PasarGuardGatewayFactory::class),
        );
    }

    public function test_marzban_v084_authenticates_reads_and_keeps_mutations_disabled(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://marzban.example.test/api/admin/token' => Http::response(['access_token' => 'fixture-access-token']),
            'https://marzban.example.test/api/system' => Http::response(['version' => '0.8.4']),
            'https://marzban.example.test/api/user/fp_provider_001' => Http::response([
                'username' => 'fp_provider_001',
                'status' => 'active',
                'data_limit' => 1_073_741_824,
                'data_limit_reset_strategy' => 'no_reset',
                'used_traffic' => 268_435_456,
                'expire' => 1_800_000_000,
                'proxies' => ['vless' => ['id' => '00000000-0000-4000-8000-000000000001']],
                'inbounds' => ['vless' => ['VLESS TCP REALITY']],
            ]),
            'https://marzban.example.test/api/inbounds' => Http::response([
                'vless' => [[
                    'tag' => 'VLESS TCP REALITY',
                    'protocol' => 'vless',
                    'network' => 'tcp',
                    'tls' => 'reality',
                    'port' => 443,
                ]],
            ]),
        ]);

        $gateway = $this->app->make(MarzbanGatewayFactory::class)->make($this->marzbanSession());

        $connection = $gateway->testConnection();
        self::assertSame(PanelOperationOutcome::Success, $connection->outcome);
        self::assertSame('0.8.4', $gateway->capabilities()->panelVersion);
        self::assertFalse($gateway->capabilities()->supports('create_service'));

        $snapshot = $gateway->findByDeterministicUsername('fp_provider_001');
        self::assertNotNull($snapshot);
        self::assertSame('fp_provider_001', $snapshot->remoteId);
        self::assertSame(1_073_741_824, $snapshot->dataLimitBytes);
        self::assertSame(268_435_456, $snapshot->usedBytes);
        self::assertSame(1_800_000_000, $snapshot->expiresAt?->getTimestamp());
        self::assertNotNull($snapshot->createEquivalenceHash);
        self::assertNotSame($snapshot->canonicalHash, $snapshot->createEquivalenceHash);

        $targets = $gateway->listCompatibleTargets();
        self::assertCount(1, $targets);
        self::assertSame('inbound', $targets[0]['type']);
        self::assertSame('VLESS TCP REALITY', $targets[0]['name']);
        self::assertStringStartsWith('marzban-inbound-', $targets[0]['id']);

        $resolution = (new RemoteIdentityResolver)->resolve(
            $gateway,
            $this->createRequest($targets[0]['id']),
        );
        self::assertSame(RemoteIdentityDisposition::Adopt, $resolution->disposition);
        self::assertSame($snapshot->createEquivalenceHash, $resolution->service?->createEquivalenceHash);

        $beforeMutation = Http::recorded()->count();
        $mutation = $gateway->createService($this->createRequest($targets[0]['id']));
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $mutation->outcome);
        self::assertSame('marzban_source_contract_mutation_disabled', $mutation->providerCode);
        self::assertSame($beforeMutation, Http::recorded()->count());

        Http::assertSent(static function (Request $request): bool {
            if ($request->url() !== 'https://marzban.example.test/api/admin/token') {
                return false;
            }

            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['username'] ?? null) === 'fixture-admin'
                && ($data['password'] ?? null) === 'fixture-password';
        });
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://marzban.example.test/api/system'
            && $request->hasHeader('Authorization', 'Bearer fixture-access-token'));
    }

    public function test_marzban_version_mismatch_is_definitive_and_safe(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://marzban.example.test/api/admin/token' => Http::response(['access_token' => 'fixture-access-token']),
            'https://marzban.example.test/api/system' => Http::response(['version' => '0.8.5']),
        ]);

        $result = $this->app->make(MarzbanGatewayFactory::class)
            ->make($this->marzbanSession())
            ->testConnection();

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('marzban_version_mismatch', $result->providerCode);
        self::assertStringNotContainsString('fixture-password', (string) $result->safeMessage);
    }

    public function test_pasarguard_v521_api_key_preserves_base_path_and_reads_by_username_id_and_group(): void
    {
        $apiKey = 'pg_key_'.'123e4567-e89b-42d3-a456-426614174000';
        Http::preventStrayRequests();
        Http::fake([
            'https://pasarguard.example.test/hpanel/api/system' => Http::response(['version' => '5.2.1']),
            'https://pasarguard.example.test/hpanel/api/user/by-username/fp_provider_001' => Http::response($this->pasarGuardUser()),
            'https://pasarguard.example.test/hpanel/api/user/by-id/42' => Http::response($this->pasarGuardUser()),
            'https://pasarguard.example.test/hpanel/api/groups' => Http::response([
                'groups' => [
                    ['id' => 4, 'name' => 'primary', 'inbound_tags' => ['VLESS'], 'is_disabled' => false, 'total_users' => 10],
                    ['id' => 5, 'name' => 'disabled', 'inbound_tags' => ['VMESS'], 'is_disabled' => true, 'total_users' => 2],
                ],
                'total' => 2,
            ]),
        ]);

        $gateway = $this->app->make(PasarGuardGatewayFactory::class)->make($this->pasarGuardApiKeySession($apiKey));

        self::assertSame(PanelOperationOutcome::Success, $gateway->testConnection()->outcome);
        self::assertSame('5.2.1', $gateway->capabilities()->panelVersion);
        self::assertFalse($gateway->capabilities()->supports('create_service'));

        $byUsername = $gateway->findByDeterministicUsername('fp_provider_001');
        self::assertNotNull($byUsername);
        self::assertSame('42', $byUsername->remoteId);
        self::assertSame('fp_provider_001', $byUsername->username);
        self::assertSame(1_073_741_824, $byUsername->dataLimitBytes);
        self::assertSame(134_217_728, $byUsername->usedBytes);
        self::assertSame(1_800_000_000, $byUsername->expiresAt?->getTimestamp());
        self::assertNotNull($byUsername->createEquivalenceHash);
        self::assertNotSame($byUsername->canonicalHash, $byUsername->createEquivalenceHash);

        $byId = $gateway->findByRemoteId('42');
        self::assertSame('fp_provider_001', $byId?->username);

        $targets = $gateway->listCompatibleTargets();
        self::assertCount(1, $targets);
        self::assertSame('pasarguard-group-4', $targets[0]['id']);
        self::assertSame('primary', $targets[0]['name']);

        $resolution = (new RemoteIdentityResolver)->resolve(
            $gateway,
            $this->createRequest($targets[0]['id']),
        );
        self::assertSame(RemoteIdentityDisposition::Adopt, $resolution->disposition);
        self::assertSame($byUsername->createEquivalenceHash, $resolution->service?->createEquivalenceHash);

        $beforeMutation = Http::recorded()->count();
        $mutation = $gateway->createService($this->createRequest($targets[0]['id']));
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $mutation->outcome);
        self::assertSame('pasarguard_source_contract_mutation_disabled', $mutation->providerCode);
        self::assertSame($beforeMutation, Http::recorded()->count());

        Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/api/admin/token'));
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://pasarguard.example.test/hpanel/api/system'
            && $request->hasHeader('X-Api-Key', $apiKey));
    }

    public function test_pasarguard_password_fallback_uses_bearer_token_without_exposing_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://pasarguard.example.test/api/admin/token' => Http::response(['access_token' => 'fixture-pg-access-token']),
            'https://pasarguard.example.test/api/system' => Http::response(['version' => '5.2.1']),
        ]);

        $gateway = $this->app->make(PasarGuardGatewayFactory::class)->make($this->pasarGuardPasswordSession());
        $result = $gateway->testConnection();

        self::assertSame(PanelOperationOutcome::Success, $result->outcome);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://pasarguard.example.test/api/system'
            && $request->hasHeader('Authorization', 'Bearer fixture-pg-access-token'));
    }

    public function test_pasarguard_invalid_api_key_is_rejected_before_any_http_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $credentials = PanelCredentials::fromInput(['api_key' => 'pg_key_invalid']);

        $this->expectException(InvalidArgumentException::class);
        try {
            (new PanelCredentialPolicy)->assertSatisfied(PanelProviderType::PasarGuard, $credentials);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_authoritative_lookup_transport_failure_is_manual_review_not_absence(): void
    {
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn(new PanelCapabilities(
            'marzban',
            '0.8.4',
            ['authoritative_username_lookup'],
            [],
        ));
        $adapter->method('createEquivalenceHash')->willReturn(hash('sha256', 'fixture-create-equivalence'));
        $adapter->expects(self::once())
            ->method('findByDeterministicUsername')
            ->willThrowException(new AuthoritativePanelLookupUnavailable('fixture_lookup_failed'));

        $resolution = (new RemoteIdentityResolver)->resolve($adapter, $this->createRequest());

        self::assertSame(RemoteIdentityDisposition::ManualReview, $resolution->disposition);
        self::assertSame('authoritative_username_lookup_unavailable', $resolution->reasonCode);
        self::assertNull($resolution->service);
    }

    public function test_provider_http_failure_message_never_contains_remote_body_or_credentials(): void
    {
        $remoteBody = 'remote diagnostic '.hash('sha256', 'fixture-sensitive-body');
        Http::preventStrayRequests();
        Http::fake([
            'https://marzban.example.test/api/admin/token' => Http::response($remoteBody, 500),
        ]);

        $result = $this->app->make(MarzbanGatewayFactory::class)
            ->make($this->marzbanSession())
            ->testConnection();

        self::assertSame(PanelOperationOutcome::RetryableFailure, $result->outcome);
        self::assertStringNotContainsString($remoteBody, (string) $result->safeMessage);
        self::assertStringNotContainsString('fixture-password', (string) $result->safeMessage);
    }

    private function marzbanSession(): PanelAdapterSession
    {
        return new PanelAdapterSession(
            PanelEndpoint::fromInput('https://marzban.example.test'),
            PanelCredentials::fromInput([
                'username' => 'fixture-admin',
                'password' => 'fixture-password',
            ]),
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
        );
    }

    private function pasarGuardApiKeySession(string $apiKey): PanelAdapterSession
    {
        return new PanelAdapterSession(
            PanelEndpoint::fromInput('https://pasarguard.example.test/hpanel'),
            PanelCredentials::fromInput(['api_key' => $apiKey]),
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
        );
    }

    private function pasarGuardPasswordSession(): PanelAdapterSession
    {
        return new PanelAdapterSession(
            PanelEndpoint::fromInput('https://pasarguard.example.test'),
            PanelCredentials::fromInput([
                'username' => 'fixture-admin',
                'password' => 'fixture-password',
            ]),
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
        );
    }

    private function createRequest(string $targetReference = 'fixture-target'): PanelCreateServiceRequest
    {
        return new PanelCreateServiceRequest(
            'provider-source-contract-operation-0001',
            'provider-source-contract-key-0001',
            'fp_provider_001',
            $targetReference,
            1_073_741_824,
            new DateTimeImmutable('@1800000000'),
            ['service_mode' => 'volume'],
        );
    }

    /** @return array<string, mixed> */
    private function pasarGuardUser(): array
    {
        return [
            'id' => 42,
            'username' => 'fp_provider_001',
            'status' => 'active',
            'used_traffic' => 134_217_728,
            'lifetime_used_traffic' => 268_435_456,
            'created_at' => '2026-08-01T00:00:00+00:00',
            'edit_at' => null,
            'online_at' => null,
            'subscription_url' => 'https://example.invalid/subscription/redacted-fixture',
            'proxy_settings' => ['vless' => ['id' => '00000000-0000-4000-8000-000000000002']],
            'expire' => '2027-01-15T08:00:00+00:00',
            'data_limit' => 1_073_741_824,
            'data_limit_reset_strategy' => 'no_reset',
            'note' => null,
            'on_hold_expire_duration' => null,
            'on_hold_timeout' => null,
            'group_ids' => [4],
            'auto_delete_in_days' => null,
            'hwid_limit' => null,
            'next_plan' => null,
            'admin' => null,
        ];
    }
}

final class ProviderContractPanelDnsResolver implements PanelDnsResolver
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}
