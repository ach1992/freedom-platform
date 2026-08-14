<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use App\Modules\Payments\Usdt\Infrastructure\GenericRestBlockchainTransactionVerificationProvider;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement USDT-003 SEC-002 INT-001 INT-002 QUA-004 */
final class UsdtGenericRestBlockchainProviderTest extends TestCase
{
    public function test_read_only_lookup_is_https_pinned_and_integer_exact(): void
    {
        $txid = '0x'.str_repeat('ab', 32);
        Http::fake([
            'https://chain.example.com/api/tx*' => Http::response([
                'event_id' => 'observation-1',
                'outcome' => 'ok',
                'status' => 'confirmed',
                'txid' => $txid,
                'network' => 'BEP20',
                'chain_id' => 56,
                'token_contract' => '0x'.str_repeat('aa', 20),
                'destination' => '0x'.str_repeat('bb', 20),
                'amount' => '1000000',
                'decimals' => 6,
                'confirmations' => 20,
                'block' => 12345678,
                'transaction_at' => '2026-08-14T06:01:00+00:00',
                'observed_at' => '2026-08-14T06:01:10+00:00',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = $this->provider();
        $evidence = $provider->lookup($this->request($txid));
        self::assertSame('success', $evidence->outcome);
        self::assertSame('success', $evidence->transactionStatus);
        self::assertSame('1000000', $evidence->amountBaseUnits);
        self::assertSame(20, $evidence->confirmations);
        self::assertSame(56, $evidence->chainId);

        Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://chain.example.com/api/tx')
            && str_contains($request->url(), 'txid='.urlencode($txid)));
    }

    public function test_float_base_units_are_rejected_instead_of_rounded(): void
    {
        $txid = '0x'.str_repeat('bc', 32);
        Http::fake([
            'https://chain.example.com/api/tx*' => Http::response([
                'outcome' => 'ok', 'status' => 'confirmed', 'txid' => $txid,
                'network' => 'BEP20', 'chain_id' => 56,
                'token_contract' => '0x'.str_repeat('aa', 20),
                'destination' => '0x'.str_repeat('bb', 20),
                'amount' => 1000000.5, 'decimals' => 6, 'confirmations' => 20,
                'transaction_at' => '2026-08-14T06:01:00+00:00',
                'observed_at' => '2026-08-14T06:01:10+00:00',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an integer string, never a float');
        $this->provider()->lookup($this->request($txid));
    }

    public function test_private_dns_resolution_fails_closed_before_request(): void
    {
        Http::fake();
        $provider = $this->provider(static fn (string $host): array => ['127.0.0.1']);
        try {
            $provider->lookup($this->request('0x'.str_repeat('cd', 32)));
            self::fail('Expected private chain-provider address to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('private/reserved', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_non_https_and_non_allowlisted_origins_are_rejected(): void
    {
        try {
            $this->newProvider('http://chain.example.com', ['chain.example.com']);
            self::fail('Expected HTTP chain-provider origin rejection.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('HTTPS', $exception->getMessage());
        }

        try {
            $this->newProvider('https://other.example.com', ['chain.example.com']);
            self::fail('Expected non-allowlisted chain-provider origin rejection.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('allowlisted', $exception->getMessage());
        }
    }

    private function provider(?callable $resolver = null): GenericRestBlockchainTransactionVerificationProvider
    {
        return $this->newProvider('https://chain.example.com', ['chain.example.com'], $resolver);
    }

    /** @param list<string> $allowedHosts */
    private function newProvider(string $baseUrl, array $allowedHosts, ?callable $resolver = null): GenericRestBlockchainTransactionVerificationProvider
    {
        return new GenericRestBlockchainTransactionVerificationProvider(
            providerCode: 'generic_chain',
            baseUrl: $baseUrl,
            lookupPath: '/api/tx',
            txidParameter: 'txid',
            fieldMap: [
                'event_id' => 'event_id', 'outcome' => 'outcome', 'status' => 'status', 'txid' => 'txid',
                'network' => 'network', 'chain_id' => 'chain_id', 'token_contract' => 'token_contract',
                'destination_address' => 'destination', 'amount_base_units' => 'amount', 'token_decimals' => 'decimals',
                'confirmations' => 'confirmations', 'block_number' => 'block',
                'transaction_at' => 'transaction_at', 'observed_at' => 'observed_at',
            ],
            outcomeMap: ['ok' => 'success', 'wait' => 'pending', 'no' => 'rejected', 'unknown' => 'uncertain'],
            statusMap: ['confirmed' => 'success', 'pending' => 'pending', 'failed' => 'failed', 'reverted' => 'reverted', 'missing' => 'not_found'],
            allowedHosts: $allowedHosts,
            resolver: $resolver ?? static fn (string $host): array => ['93.184.216.34'],
        );
    }

    private function request(string $txid): UsdtBlockchainVerificationRequest
    {
        return new UsdtBlockchainVerificationRequest(
            $txid,
            'BEP20',
            56,
            '0x'.str_repeat('aa', 20),
            '0x'.str_repeat('bb', 20),
            '1000000',
            6,
            15,
        );
    }
}
