<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Infrastructure;

use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderRequest;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use DateTimeImmutable;
use DomainException;

final class FakeGiftCardVerificationProvider implements GiftCardVerificationProvider
{
    /** @var array<string, GiftCardProviderEvidence> */
    private array $responses = [];

    public function __construct(
        private readonly string $providerCode = 'fake_gift_card',
        private readonly ?GiftCardProviderCapabilities $configuredCapabilities = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $providerCode) !== 1) {
            throw new DomainException('Fake gift-card provider code is invalid.');
        }
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function capabilities(): GiftCardProviderCapabilities
    {
        return $this->configuredCapabilities ?? new GiftCardProviderCapabilities(true, true, true, true, true);
    }

    public function validate(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->response('validate', $request);
    }

    public function reserve(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->response('reserve', $request);
    }

    public function redeem(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->response('redeem', $request);
    }

    public function release(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->response('release', $request);
    }

    public function status(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->response('status', $request);
    }

    public function put(string $operation, string $operationKey, GiftCardProviderEvidence $evidence): void
    {
        $this->assertOperation($operation);
        if ($evidence->operation !== $operation) {
            throw new DomainException('Fake gift-card evidence operation does not match response slot.');
        }
        $this->responses[$operation."\0".$operationKey] = $evidence;
    }

    private function response(string $operation, GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        $this->assertOperation($operation);
        $capabilities = $this->capabilities();
        $supported = match ($operation) {
            'validate' => $capabilities->validate,
            'reserve' => $capabilities->reserve,
            'redeem' => $capabilities->redeem,
            'release' => $capabilities->release,
            'status' => $capabilities->status,
            default => false,
        };
        if (! $supported) {
            return new GiftCardProviderEvidence(
                $operation,
                'unavailable',
                'unsupported',
                'fake-unsupported-'.hash('sha256', $request->operationKey),
                null,
                null,
                null,
                null,
                null,
                new DateTimeImmutable('now'),
                hash('sha256', 'fake-unsupported:'.$operation.':'.$request->operationKey),
                ['reason' => 'unsupported'],
            );
        }

        return $this->responses[$operation."\0".$request->operationKey]
            ?? new GiftCardProviderEvidence(
                $operation,
                'unavailable',
                'not_configured',
                'fake-missing-'.hash('sha256', $request->operationKey),
                null,
                null,
                null,
                null,
                null,
                new DateTimeImmutable('now'),
                hash('sha256', 'fake-missing:'.$operation.':'.$request->operationKey),
                ['reason' => 'not_configured'],
            );
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, ['validate', 'reserve', 'redeem', 'release', 'status'], true)) {
            throw new DomainException('Fake gift-card operation is invalid.');
        }
    }
}
