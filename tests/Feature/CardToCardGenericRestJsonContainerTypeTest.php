<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\CardToCard\Infrastructure\GenericRestBankTransactionVerificationProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement C2C-003 C2C-004 PAY-003 SEC-002 SEC-008 INT-001 INT-002 QUA-001 QUA-004 */
final class CardToCardGenericRestJsonContainerTypeTest extends TestCase
{
    public function test_array_index_path_rejects_numeric_key_json_object_from_raw_provider_body(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response(
                '{"transactions":[{"items":{"0":{"id":"raw-provider-secret-object-id"}},"dest":"4242424242424242","amount":"12345","state":"DONE","occurred":"2026-08-14T10:15:00+00:00"}],"next_cursor":null}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $provider = $this->provider([
            'transaction_id' => '$.items[0].id',
            'destination_card' => 'dest',
            'amount' => 'amount',
            'status' => 'state',
            'occurred_at' => 'occurred',
        ]);

        try {
            $provider->fetch(null);
            self::fail('Expected an array index path to reject a numeric-key JSON object.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Generic bank mapped field transaction_id path did not resolve.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('raw-provider-secret-object-id', $exception->getMessage());
        }
    }

    public function test_direct_key_mapping_preserves_legacy_evidence_hash_with_nested_numeric_key_object(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response(
                '{"transactions":[{"tx_id":"provider-tx-direct","event_id":"provider-event-direct","dest":"4242424242424242","amount":"12345","state":"DONE","occurred":"2026-08-14T10:15:00+00:00","meta":{"0":{"z":"last","a":"first"}}}],"next_cursor":null}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $page = $this->provider([
            'transaction_id' => 'tx_id',
            'event_id' => 'event_id',
            'destination_card' => 'dest',
            'amount' => 'amount',
            'status' => 'state',
            'occurred_at' => 'occurred',
        ])->fetch(null);

        self::assertCount(1, $page->transactions);
        self::assertSame('provider-tx-direct', $page->transactions[0]->providerTransactionId);
        self::assertSame('provider-event-direct', $page->transactions[0]->providerEventId);
        self::assertSame(
            'a20f778b49f7677bc17fac803c2d94f5463f52a61a209f0056662917800d1438',
            $page->transactions[0]->evidencePayloadHash,
            'Direct-key decoding must retain the pre-#128 associative canonicalization hash.',
        );
    }

    /** @param array<string, string> $fieldMap */
    private function provider(array $fieldMap): GenericRestBankTransactionVerificationProvider
    {
        return new GenericRestBankTransactionVerificationProvider(
            'generic_bank',
            'https://bank.example.test',
            '/v1/transactions',
            $fieldMap,
            ['DONE' => 'settled'],
            ['bank.example.test'],
            resolver: static fn (string $host): array => ['8.8.8.8'],
        );
    }
}
