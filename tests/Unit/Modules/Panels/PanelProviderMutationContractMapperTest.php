<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Infrastructure\MarzbanMutationContractMapper;
use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use App\Modules\Panels\Infrastructure\PanelMappedMutationRequest;
use App\Modules\Panels\Infrastructure\PasarGuardMutationContractMapper;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 PRV-002 PRV-003 SVC-002 SVC-004 SEC-002 QUA-001 */
final class PanelProviderMutationContractMapperTest extends TestCase
{
    public function test_marzban_v084_maps_all_pinned_mutations_and_delivery_fixture(): void
    {
        $fixture = $this->fixture('marzban-v0.8.4-mutations.json');
        $mapper = new MarzbanMutationContractMapper;
        $request = $this->request((string) $fixture['target_reference']);
        $remoteId = (string) $fixture['remote_id'];
        $current = $this->snapshot($remoteId, $request->username, 1_073_741_824);

        $create = $mapper->createRequest($request);
        $this->assertMappedRequest($fixture['requests']['create_service'], $create);
        self::assertInstanceOf(\stdClass::class, $create->payload['proxies']['vless'] ?? null);
        $this->assertMappedRequest(
            $fixture['requests']['update_expiry'],
            $mapper->updateExpiryRequest($remoteId, new DateTimeImmutable('@1800003600')),
        );
        $this->assertMappedRequest(
            $fixture['requests']['set_data_allowance'],
            $mapper->updateDataAllowanceRequest($remoteId, 2_147_483_648, DataAllowanceMode::Set),
        );
        $this->assertMappedRequest(
            $fixture['requests']['add_data_allowance'],
            $mapper->updateDataAllowanceRequest($remoteId, 536_870_912, DataAllowanceMode::Add, $current),
        );
        $this->assertMappedRequest($fixture['requests']['reset_usage'], $mapper->resetUsageRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['suspend'], $mapper->suspendRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['activate'], $mapper->activateRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['delete'], $mapper->deleteRequest($remoteId));
        $this->assertMappedRequest(
            $fixture['requests']['rotate_subscription_link'],
            $mapper->rotateSubscriptionLinkRequest($remoteId),
        );

        self::assertTrue($mapper->createEquivalent($request, $fixture['create_response']));
        $drifted = $fixture['create_response'];
        $drifted['data_limit'] = 1;
        self::assertFalse($mapper->createEquivalent($request, $drifted));

        $delivery = $mapper->deliveryArtifacts($fixture['create_response']);
        self::assertSame('[SENSITIVE_DELIVERY_ARTIFACTS]', (string) $delivery);
        self::assertCount(2, $delivery->revealForAuthorizedDelivery());
    }

    public function test_pasarguard_v521_maps_all_pinned_mutations_and_delivery_fixture(): void
    {
        $fixture = $this->fixture('pasarguard-v5.2.1-mutations.json');
        $mapper = new PasarGuardMutationContractMapper;
        $request = $this->request((string) $fixture['target_reference']);
        $remoteId = (string) $fixture['remote_id'];
        $current = $this->snapshot($remoteId, $request->username, 1_073_741_824);

        $this->assertMappedRequest($fixture['requests']['create_service'], $mapper->createRequest($request));
        $this->assertMappedRequest(
            $fixture['requests']['update_expiry'],
            $mapper->updateExpiryRequest($remoteId, new DateTimeImmutable('@1800003600')),
        );
        $this->assertMappedRequest(
            $fixture['requests']['set_data_allowance'],
            $mapper->updateDataAllowanceRequest($remoteId, 2_147_483_648, DataAllowanceMode::Set),
        );
        $this->assertMappedRequest(
            $fixture['requests']['add_data_allowance'],
            $mapper->updateDataAllowanceRequest($remoteId, 536_870_912, DataAllowanceMode::Add, $current),
        );
        $this->assertMappedRequest($fixture['requests']['reset_usage'], $mapper->resetUsageRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['suspend'], $mapper->suspendRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['activate'], $mapper->activateRequest($remoteId));
        $this->assertMappedRequest($fixture['requests']['delete'], $mapper->deleteRequest($remoteId));
        $this->assertMappedRequest(
            $fixture['requests']['rotate_subscription_link'],
            $mapper->rotateSubscriptionLinkRequest($remoteId),
        );

        self::assertTrue($mapper->createEquivalent($request, $fixture['create_response']));
        $drifted = $fixture['create_response'];
        $drifted['group_ids'] = [99];
        self::assertFalse($mapper->createEquivalent($request, $drifted));

        $delivery = $mapper->deliveryArtifacts($fixture['create_response']);
        self::assertSame('[SENSITIVE_DELIVERY_ARTIFACTS]', (string) $delivery);
        self::assertCount(1, $delivery->revealForAuthorizedDelivery());
    }

    public function test_additive_data_mapping_requires_authoritative_lookup_and_rejects_zero_byte_mutations(): void
    {
        foreach ([new MarzbanMutationContractMapper, new PasarGuardMutationContractMapper] as $mapper) {
            $remoteId = $mapper instanceof MarzbanMutationContractMapper ? 'fp_provider_001' : '42';

            try {
                $mapper->updateDataAllowanceRequest($remoteId, 1, DataAllowanceMode::Add);
                self::fail('Additive mutation must require authoritative remote state.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Authoritative lookup', $exception->getMessage());
            }

            try {
                $mapper->updateDataAllowanceRequest($remoteId, 0, DataAllowanceMode::Set);
                self::fail('Zero-byte provider mutations must not map to unlimited sentinel values.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('positive byte count', $exception->getMessage());
            }
        }
    }

    public function test_create_equivalence_uses_only_provider_preserved_fields(): void
    {
        foreach ([
            [new MarzbanMutationContractMapper, 'marzban-v0.8.4-mutations.json'],
            [new PasarGuardMutationContractMapper, 'pasarguard-v5.2.1-mutations.json'],
        ] as [$mapper, $fixtureName]) {
            $fixture = $this->fixture($fixtureName);
            $request = $this->request((string) $fixture['target_reference']);
            $provider = $fixture['create_response'];
            $provider['volatile_fixture_field'] = 'ignored-by-equivalence';

            self::assertTrue($mapper->createEquivalent($request, $provider));
        }
    }

    public function test_mutation_outcomes_fail_closed_on_uncertainty_and_do_not_retry_conflicts(): void
    {
        foreach ([new MarzbanMutationContractMapper, new PasarGuardMutationContractMapper] as $mapper) {
            $transport = $mapper->classifyMutation(
                'update_expiry',
                new PanelHttpExchange(null, null, false, true),
            );
            self::assertSame(PanelOperationOutcome::UncertainResult, $transport->outcome);
            self::assertTrue($transport->requiresDiscoveryBeforeRetry);

            $serverError = $mapper->classifyMutation(
                'update_expiry',
                new PanelHttpExchange(503, null, false, false),
            );
            self::assertSame(PanelOperationOutcome::UncertainResult, $serverError->outcome);
            self::assertTrue($serverError->requiresDiscoveryBeforeRetry);

            $malformed = $mapper->classifyMutation(
                'update_expiry',
                new PanelHttpExchange(200, null, true, false),
            );
            self::assertSame(PanelOperationOutcome::UncertainResult, $malformed->outcome);
            self::assertTrue($malformed->requiresDiscoveryBeforeRetry);

            $rateLimited = $mapper->classifyMutation(
                'update_expiry',
                new PanelHttpExchange(429, null, false, false),
            );
            self::assertSame(PanelOperationOutcome::RetryableFailure, $rateLimited->outcome);
            self::assertFalse($rateLimited->requiresDiscoveryBeforeRetry);
            self::assertFalse($rateLimited->manualReview);

            $conflict = $mapper->classifyMutation(
                'update_expiry',
                new PanelHttpExchange(409, null, false, false),
            );
            self::assertSame(PanelOperationOutcome::DefinitiveFailure, $conflict->outcome);
            self::assertFalse($conflict->requiresDiscoveryBeforeRetry);
            self::assertTrue($conflict->manualReview);
        }
    }

    public function test_exact_success_status_and_payload_are_required(): void
    {
        $marzban = new MarzbanMutationContractMapper;
        $marzbanFixture = $this->fixture('marzban-v0.8.4-mutations.json');
        $marzbanSuccess = $marzban->classifyMutation(
            'update_expiry',
            new PanelHttpExchange(200, $marzbanFixture['create_response'], false, false),
        );
        self::assertSame(PanelOperationOutcome::Success, $marzbanSuccess->outcome);
        self::assertSame(
            PanelOperationOutcome::Success,
            $marzban->classifyMutation('delete', new PanelHttpExchange(200, ['detail' => 'deleted'], false, false))->outcome,
        );

        $pasarGuard = new PasarGuardMutationContractMapper;
        $pasarFixture = $this->fixture('pasarguard-v5.2.1-mutations.json');
        self::assertSame(
            PanelOperationOutcome::Success,
            $pasarGuard->classifyMutation('create_service', new PanelHttpExchange(201, $pasarFixture['create_response'], false, false))->outcome,
        );
        self::assertSame(
            PanelOperationOutcome::Success,
            $pasarGuard->classifyMutation('delete', new PanelHttpExchange(204, [], false, false))->outcome,
        );
        self::assertSame(
            PanelOperationOutcome::UncertainResult,
            $pasarGuard->classifyMutation('create_service', new PanelHttpExchange(200, $pasarFixture['create_response'], false, false))->outcome,
        );
    }

    public function test_delivery_mapping_rejects_non_https_subscription_urls(): void
    {
        foreach ([
            [new MarzbanMutationContractMapper, 'marzban-v0.8.4-mutations.json'],
            [new PasarGuardMutationContractMapper, 'pasarguard-v5.2.1-mutations.json'],
        ] as [$mapper, $fixtureName]) {
            $fixture = $this->fixture($fixtureName);
            $provider = $fixture['create_response'];
            $provider['subscription_url'] = 'http://example.invalid/unsafe';

            try {
                $mapper->deliveryArtifacts($provider);
                self::fail('Unsafe provider delivery URL must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('subscription URL', $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 4).'/tests/Fixtures/Panels/'.$name;
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function request(string $targetReference): PanelCreateServiceRequest
    {
        return new PanelCreateServiceRequest(
            'provider-mutation-contract-operation-0001',
            'provider-mutation-contract-key-0001',
            'fp_provider_001',
            $targetReference,
            1_073_741_824,
            new DateTimeImmutable('@1800000000'),
            ['service_mode' => 'volume'],
        );
    }

    private function snapshot(string $remoteId, string $username, int $dataLimitBytes): RemoteServiceSnapshot
    {
        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            PanelServiceStatus::Active,
            $dataLimitBytes,
            0,
            new DateTimeImmutable('@1800000000'),
            hash('sha256', 'provider-mutation-current-fixture'),
        );
    }

    /** @param array<string, mixed> $expected */
    private function assertMappedRequest(array $expected, PanelMappedMutationRequest $actual): void
    {
        self::assertSame($expected['method'], $actual->method);
        self::assertSame($expected['path'], $actual->path);
        if ($expected['payload'] === null) {
            self::assertNull($actual->payload);

            return;
        }
        self::assertNotNull($actual->payload);
        $normalizedActual = json_decode(
            json_encode($actual->payload, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($expected['payload'], $normalizedActual);
    }
}
