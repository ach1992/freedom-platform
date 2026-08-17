<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\CardToCard\Infrastructure\GenericRestBankTransactionVerificationProvider;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement C2C-003 C2C-004 PAY-003 SEC-002 SEC-008 INT-001 INT-002 QUA-001 QUA-004 */
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

    public function test_generic_rest_provider_extracts_nested_object_and_bounded_array_path_fields(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response([
                'transactions' => [[
                    'payload' => [
                        'identity' => ['tx' => 12345, 'event' => 'provider-event-nested'],
                        'cards' => [
                            ['number' => '4242-4242-4242-4242'],
                            ['number' => '1111-2222-3333-4444'],
                        ],
                        'money' => ['amount' => '12345'],
                        'state' => ['code' => 'DONE'],
                        'time' => ['occurred' => '2026-08-14T10:15:00+00:00'],
                        'sender' => ['card' => '6037-99**-****-1234', 'name' => 'Nested Customer'],
                        'reference' => 'nested-ref-1',
                    ],
                ]],
                'next_cursor' => 'nested-next',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = $this->provider(
            resolver: static fn (string $host): array => ['8.8.8.8'],
            fieldMap: [
                'transaction_id' => '$.payload.identity.tx',
                'event_id' => '$.payload.identity.event',
                'destination_card' => '$.payload.cards[0].number',
                'amount' => '$.payload.money.amount',
                'status' => '$.payload.state.code',
                'occurred_at' => '$.payload.time.occurred',
                'sender_card' => '$.payload.sender.card',
                'sender_name' => '$.payload.sender.name',
                'reference' => '$.payload.reference',
            ],
        );

        $page = $provider->fetch(null);
        self::assertSame('nested-next', $page->nextCursor);
        self::assertCount(1, $page->transactions);
        $transaction = $page->transactions[0];
        self::assertSame('12345', $transaction->providerTransactionId, 'Integer path terminals remain a supported scalar exactly as direct mappings do.');
        self::assertSame('provider-event-nested', $transaction->providerEventId);
        self::assertSame('4242424242424242', $transaction->destinationCardNumber);
        self::assertSame(123450, $transaction->amountIrr);
        self::assertSame('settled', $transaction->status);
        self::assertSame('6037991234', $transaction->senderCardNumber);
        self::assertSame('Nested Customer', $transaction->senderName);
        self::assertSame('nested-ref-1', $transaction->reference);
    }

    public function test_generic_rest_provider_rejects_unsafe_or_unbounded_mapping_syntax_during_configuration(): void
    {
        $syntaxInvalid = [
            '',
            '$',
            '$.payload..tx',
            '$..payload.tx',
            '$.items[-1].id',
            '$.items[*].id',
            '$.items[?(@.status)].id',
            '$.items[0:1].id',
            '$.items["0"].id',
            '$.payload.fn()',
            '{{payload.tx}}',
            '$(id)',
            '$.payload.tx;DROP_TABLE',
        ];
        foreach ($syntaxInvalid as $mapping) {
            $this->assertRejectedMapping(
                $mapping,
                'Generic bank provider field mapping path syntax is invalid.',
            );
        }

        $this->assertRejectedMapping(
            '$.'.str_repeat('a', 255),
            'Generic bank provider field mapping path exceeds the maximum length.',
        );
        $this->assertRejectedMapping(
            '$'.str_repeat('.a', 13),
            'Generic bank provider field mapping path exceeds the maximum depth.',
        );
        $this->assertRejectedMapping(
            '$.items[256].id',
            'Generic bank provider field mapping array index is out of bounds.',
        );
    }

    public function test_generic_rest_provider_mapping_failures_are_sanitized_and_fail_closed(): void
    {
        $cases = [
            [
                'row' => $this->rowWithNestedValue(missing: true),
                'message' => 'Generic bank mapped field transaction_id path did not resolve.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: ['protected' => 'raw-provider-secret-object']),
                'message' => 'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: ['raw-provider-secret-array']),
                'message' => 'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: null),
                'message' => 'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: true),
                'message' => 'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: 12.5),
                'message' => 'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
            ],
            [
                'row' => $this->rowWithNestedValue(value: str_repeat('x', 4097)),
                'message' => 'Generic bank mapped field transaction_id terminal value exceeds the size limit.',
            ],
        ];

        foreach ($cases as $case) {
            Http::fake([
                'https://bank.example.test/v1/transactions*' => Http::response([
                    'transactions' => [$case['row']],
                    'next_cursor' => null,
                    'protected_payload_marker' => 'raw-provider-secret-envelope',
                ], 200, ['Content-Type' => 'application/json']),
            ]);

            $provider = $this->provider(
                resolver: static fn (string $host): array => ['8.8.8.8'],
                fieldMap: array_replace($this->directFieldMap(), ['transaction_id' => '$.nested.value']),
            );

            try {
                $provider->fetch(null);
                self::fail('Expected the configured nested mapping to fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame($case['message'], $exception->getMessage());
                self::assertStringNotContainsString('raw-provider-secret', $exception->getMessage());
            }
        }
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

    private function assertRejectedMapping(string $mapping, string $expectedMessage): void
    {
        try {
            $this->provider(
                resolver: static fn (string $host): array => ['8.8.8.8'],
                fieldMap: array_replace($this->directFieldMap(), ['transaction_id' => $mapping]),
            );
            self::fail('Expected unsafe Generic REST field mapping configuration to be rejected.');
        } catch (DomainException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function rowWithNestedValue(mixed $value = null, bool $missing = false): array
    {
        $row = [
            'tx_id' => 'provider-tx-runtime',
            'event_id' => 'provider-event-runtime',
            'dest' => '4242424242424242',
            'amount' => '12345',
            'state' => 'DONE',
            'occurred' => '2026-08-14T10:15:00+00:00',
        ];
        $row['nested'] = $missing ? [] : ['value' => $value];

        return $row;
    }

    /** @return array<string, string> */
    private function directFieldMap(): array
    {
        return [
            'transaction_id' => 'tx_id',
            'event_id' => 'event_id',
            'destination_card' => 'dest',
            'amount' => 'amount',
            'status' => 'state',
            'occurred_at' => 'occurred',
            'sender_card' => 'sender',
            'sender_name' => 'name',
            'reference' => 'ref',
        ];
    }

    /** @param null|array<string, string> $fieldMap */
    private function provider(callable $resolver, ?array $fieldMap = null): GenericRestBankTransactionVerificationProvider
    {
        return new GenericRestBankTransactionVerificationProvider(
            'generic_bank',
            'https://bank.example.test',
            '/v1/transactions',
            $fieldMap ?? $this->directFieldMap(),
            ['WAITING' => 'pending', 'DONE' => 'settled', 'REVERSED' => 'reversed', 'FAILED' => 'failed'],
            ['bank.example.test'],
            amountUnit: 'TOMAN',
            authType: 'bearer',
            credential: 'test-secret',
            resolver: $resolver,
        );
    }
}
