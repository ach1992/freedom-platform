<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use Closure;
use DateTimeImmutable;
use LogicException;
use RuntimeException;

final class ServiceDeliveryEffectTestDoubles implements PanelAdapter, PanelAdapterFactory, ProtectedTelegramMessageSender
{
    /** @var list<string> */
    public array $panelCalls = [];

    /** @var list<array{telegram_user_id:int,text:string}> */
    public array $sendCalls = [];

    public ?Closure $beforeDeliveryArtifacts = null;

    public bool $throwOnDeliveryArtifacts = false;

    public ?Closure $beforeSend = null;

    /** @var array<string, RemoteServiceSnapshot> */
    private array $servicesByUsername = [];

    public function __construct(
        private string $deliveryLink,
        public ProtectedTelegramSendResult $sendResult,
    ) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::Fake;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return $this;
    }

    public function testConnection(): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_connection_ok', 'Test panel is healthy.');
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities('fake', '1.0.0', [
            'activate',
            'authoritative_username_lookup',
            'create_service',
            'delete',
            'reset_usage',
            'rotate_subscription_link',
            'suspend',
        ], ['fake-default']);
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        $this->panelCalls[] = 'lookup_remote_id';
        foreach ($this->servicesByUsername as $service) {
            if (hash_equals($service->remoteId, $remoteId)) {
                return $service;
            }
        }

        return null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        $this->panelCalls[] = 'lookup_username';

        return $this->servicesByUsername[$username] ?? null;
    }

    public function createEquivalenceHash(PanelCreateServiceRequest $request): string
    {
        return hash('sha256', json_encode([
            'username' => $request->username,
            'target_reference' => $request->targetReference,
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at' => $request->expiresAt?->format(DATE_ATOM),
            'attributes' => $request->validatedAttributes,
        ], JSON_THROW_ON_ERROR));
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        $this->panelCalls[] = 'create';
        $hash = $this->createEquivalenceHash($request);
        $service = new RemoteServiceSnapshot(
            'delivery-test-'.substr(hash('sha256', $request->idempotencyKey), 0, 24),
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $hash,
            $hash,
        );
        $this->servicesByUsername[$service->username] = $service;

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $service,
            'test_service_created',
            'Test service created.',
        );
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service delivery effect tests.');
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, DateTimeImmutable $expiresAt): PanelOperationResult
    {
        throw new LogicException('Not used by Service delivery effect tests.');
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        throw new LogicException('Not used by Service delivery effect tests.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_reset_ok', 'Reset succeeded.');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_suspend_ok', 'Suspend succeeded.');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_activate_ok', 'Activate succeeded.');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_delete_ok', 'Delete succeeded.');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_rotate_ok', 'Rotate succeeded.');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        $this->panelCalls[] = 'delivery_artifacts';
        if ($this->beforeDeliveryArtifacts !== null) {
            ($this->beforeDeliveryArtifacts)();
        }
        if ($this->throwOnDeliveryArtifacts) {
            throw new RuntimeException('Simulated protected artifact retrieval failure.');
        }

        return new SensitiveDeliveryArtifacts([$this->deliveryLink]);
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service delivery effect tests.');
    }

    public function listCompatibleTargets(): array
    {
        return [[
            'id' => 'fake-default',
            'type' => 'inbound',
            'name' => 'Test target',
            'capabilities' => $this->capabilities()->operations,
        ]];
    }

    public function send(int $telegramUserId, string $text): ProtectedTelegramSendResult
    {
        $this->sendCalls[] = ['telegram_user_id' => $telegramUserId, 'text' => $text];
        if ($this->beforeSend !== null) {
            ($this->beforeSend)();
        }

        return $this->sendResult;
    }

    public function resetCalls(): void
    {
        $this->panelCalls = [];
        $this->sendCalls = [];
    }
}
