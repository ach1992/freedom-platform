<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\CardToCard\Infrastructure\GenericRestBankTransactionVerificationProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement C2C-003 C2C-004 SEC-002 INT-001 INT-002 QUA-004 */
final class CardToCardGenericRestProviderTest extends TestCase
{
    public function test_generic_rest_provider_pins_public_host_normalizes_toman_and_maps_only_allowlisted_fields(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response([
                'transactions' => [[
                    'tx_id' => 'provider-tx-1',
                    'event_id' => 'provider-event-1',
                    'dest' => '4242-4242-4242-4242',
                    'amount' => '12345',
                    'state' => 'DONE',
                    'occurred' => '2026-08-14T10:15:00+00:00',
                    'sender' => '6037-99**-****-1234',
                    'name' => 'Customer',
                    'ref' => 'bank-ref-1',
                    'ignored_secret_like_field' => 'not mapped',
                ]],
                'next_cursor' => 'next-2',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = $this->provider(resolver: static fn (string $host): array => ['8.8.8.8']);
        $page = $provider->fetch('cursor-1');

        self::assertSame('generic_bank', $provider->code());
        self::assertSame('next-2', $page->nextCursor);
        self::assertCount(1, $page->transactions);
        $transaction = $page->transactions[0];
        self::assertSame('provider-tx-1', $transaction->providerTransactionId);
        self::assertSame('provider-event-1', $transaction->providerEventId);
        self::assertSame('4242424242424242', $transaction->destinationCardNumber);
        self::assertSame(123450, $transaction->amountIrr);
        self::assertSame('settled', $transaction->status);
        self::assertSame('6037991234', $transaction->senderCardNumber);
        self::assertSame('Customer', $transaction->senderName);
        self::assertSame('bank-ref-1', $transaction->reference);
        self::assertSame(64, strlen($transaction->evidencePayloadHash));

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'https://bank.example.test/v1/transactions?cursor=cursor-1'
                && $request->hasHeader('Authorization', 'Bearer test-secret');
        });
    }

    public function test_generic_rest_provider_rejects_float_money_and_private_dns_resolution(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response([
                'transactions' => [[
                    'tx_id' => 'provider-tx-float',
                    'event_id' => 'provider-event-float',
                    'dest' => '4242424242424242',
                    'amount' => 12.5,
                    'state' => 'DONE',
                    'occurred' => '2026-08-14T10:15:00+00:00',
                ]],
                'next_cursor' => null,
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $publicProvider = $this->provider(resolver: static fn (string $host): array => ['1.1.1.1']);
        try {
            $publicProvider->fetch(null);
            self::fail('Expected generic bank provider to reject floating-point money.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('never a float', $exception->getMessage());
        }

        $privateProvider = $this->provider(resolver: static fn (string $host): array => ['127.0.0.1']);
        try {
            $privateProvider->fetch(null);
            self::fail('Expected generic bank provider to reject private/reserved DNS resolution.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('private/reserved', $exception->getMessage());
        }
    }

    private function provider(callable $resolver): GenericRestBankTransactionVerificationProvider
    {
        return new GenericRestBankTransactionVerificationProvider(
            'generic_bank',
            'https://bank.example.test',
            '/v1/transactions',
            [
                'transaction_id' => 'tx_id',
                'event_id' => 'event_id',
                'destination_card' => 'dest',
                'amount' => 'amount',
                'status' => 'state',
                'occurred_at' => 'occurred',
                'sender_card' => 'sender',
                'sender_name' => 'name',
                'reference' => 'ref',
            ],
            ['WAITING' => 'pending', 'DONE' => 'settled', 'REVERSED' => 'reversed', 'FAILED' => 'failed'],
            ['bank.example.test'],
            amountUnit: 'TOMAN',
            authType: 'bearer',
            credential: 'test-secret',
            resolver: $resolver,
        );
    }
}
