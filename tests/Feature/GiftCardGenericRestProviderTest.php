<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderRequest;
use App\Modules\Payments\GiftCard\Infrastructure\GenericRestGiftCardVerificationProvider;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement GFT-002 GFT-003 SEC-002 INT-001 INT-002 QUA-004 */
final class GiftCardGenericRestProviderTest extends TestCase
{
    public function test_validation_only_provider_is_supported_with_pinned_https_and_stable_idempotency_key(): void
    {
        $operationKey = hash('sha256', 'gift-card-generic-validation');
        Http::fake([
            'https://gift.example.com/api/validate' => Http::response([
                'event_id' => 'validate-event-1',
                'outcome' => 'ok',
                'status' => 'valid',
                'value' => 1_000_000,
                'currency' => 'IRR',
                'brand' => 'Steam',
                'region' => 'GLOBAL',
                'occurred_at' => '2026-08-14T14:02:00+00:00',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = $this->provider(['validate' => '/api/validate']);
        self::assertTrue($provider->capabilities()->validate);
        self::assertFalse($provider->capabilities()->redeem);

        $evidence = $provider->validate($this->request($operationKey));
        self::assertSame('validate', $evidence->operation);
        self::assertSame('success', $evidence->outcome);
        self::assertSame('valid', $evidence->status);
        self::assertSame(1_000_000, $evidence->faceValue);
        self::assertSame('IRR', $evidence->currency);

        Http::assertSent(static function ($request) use ($operationKey): bool {
            return $request->url() === 'https://gift.example.com/api/validate'
                && $request->method() === 'POST'
                && $request->hasHeader('Idempotency-Key', $operationKey)
                && $request['operation_key'] === $operationKey
                && $request['code'] === 'STEAM-TEST-0001';
        });
    }

    public function test_financial_float_is_rejected_instead_of_rounded(): void
    {
        Http::fake([
            'https://gift.example.com/api/validate' => Http::response([
                'event_id' => 'validate-event-float',
                'outcome' => 'ok',
                'status' => 'valid',
                'value' => 1000000.5,
                'currency' => 'IRR',
                'brand' => 'Steam',
                'region' => 'GLOBAL',
                'occurred_at' => '2026-08-14T14:02:00+00:00',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integer, never a float');
        $this->provider(['validate' => '/api/validate'])->validate($this->request(hash('sha256', 'float')));
    }

    public function test_private_dns_resolution_fails_closed_before_provider_request(): void
    {
        Http::fake();
        $provider = $this->provider(
            ['validate' => '/api/validate'],
            static fn (string $host): array => ['127.0.0.1'],
        );

        try {
            $provider->validate($this->request(hash('sha256', 'private-dns')));
            self::fail('Expected private provider resolution to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('private/reserved', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_non_https_or_non_allowlisted_origins_are_rejected_at_configuration_boundary(): void
    {
        try {
            new GenericRestGiftCardVerificationProvider(
                providerCode: 'generic_gift',
                baseUrl: 'http://gift.example.com',
                operationPaths: ['validate' => '/api/validate'],
                fieldMap: $this->fieldMap(),
                outcomeMap: ['ok' => 'success'],
                statusMap: ['valid' => 'valid'],
                allowedHosts: ['gift.example.com'],
            );
            self::fail('Expected non-HTTPS gift-card provider origin to be rejected.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('HTTPS', $exception->getMessage());
        }

        try {
            new GenericRestGiftCardVerificationProvider(
                providerCode: 'generic_gift',
                baseUrl: 'https://other.example.com',
                operationPaths: ['validate' => '/api/validate'],
                fieldMap: $this->fieldMap(),
                outcomeMap: ['ok' => 'success'],
                statusMap: ['valid' => 'valid'],
                allowedHosts: ['gift.example.com'],
            );
            self::fail('Expected non-allowlisted gift-card provider origin to be rejected.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('allowlisted', $exception->getMessage());
        }
    }

    /** @param array<string,string> $operationPaths */
    private function provider(array $operationPaths, ?callable $resolver = null): GenericRestGiftCardVerificationProvider
    {
        return new GenericRestGiftCardVerificationProvider(
            providerCode: 'generic_gift',
            baseUrl: 'https://gift.example.com',
            operationPaths: $operationPaths,
            fieldMap: $this->fieldMap(),
            outcomeMap: ['ok' => 'success', 'wait' => 'pending', 'no' => 'rejected'],
            statusMap: ['valid' => 'valid', 'redeemed' => 'redeemed', 'missing' => 'missing'],
            allowedHosts: ['gift.example.com'],
            resolver: $resolver ?? static fn (string $host): array => ['93.184.216.34'],
        );
    }

    /** @return array<string,string> */
    private function fieldMap(): array
    {
        return [
            'event_id' => 'event_id',
            'transaction_id' => 'transaction_id',
            'outcome' => 'outcome',
            'status' => 'status',
            'face_value' => 'value',
            'currency' => 'currency',
            'brand' => 'brand',
            'region' => 'region',
            'occurred_at' => 'occurred_at',
        ];
    }

    private function request(string $operationKey): GiftCardProviderRequest
    {
        return new GiftCardProviderRequest(
            $operationKey,
            '01K2A1B2C3D4E5F6G7H8J9K0MN',
            'steam-global',
            'Steam',
            'GLOBAL',
            'IRR',
            1_000_000,
            'STEAM-TEST-0001',
            null,
        );
    }
}
