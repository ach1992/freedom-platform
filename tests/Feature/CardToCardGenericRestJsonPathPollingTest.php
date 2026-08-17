<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\CardToCard\Infrastructure\GenericRestBankTransactionVerificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement C2C-003 C2C-004 PAY-003 SEC-002 SEC-008 INT-001 INT-002 QUA-001 QUA-004 */
final class CardToCardGenericRestJsonPathPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_failure_stops_before_ingest_match_capture_or_cursor_advance_and_uses_provider_failure_taxonomy(): void
    {
        Http::fake([
            'https://bank.example.test/v1/transactions*' => Http::response([
                'transactions' => [[
                    'nested' => [
                        'tx' => ['protected' => 'raw-provider-secret-value'],
                    ],
                    'dest' => '4242424242424242',
                    'amount' => '10000',
                    'state' => 'DONE',
                    'occurred' => '2026-08-14T10:15:00+00:00',
                ]],
                'next_cursor' => 'must-not-advance',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = new GenericRestBankTransactionVerificationProvider(
            'generic_bank',
            'https://bank.example.test',
            '/v1/transactions',
            [
                'transaction_id' => '$.nested.tx',
                'destination_card' => 'dest',
                'amount' => 'amount',
                'status' => 'state',
                'occurred_at' => 'occurred',
            ],
            ['DONE' => 'settled'],
            ['bank.example.test'],
            resolver: static fn (string $host): array => ['8.8.8.8'],
        );

        try {
            $this->app->make(CardToCardProviderPollingService::class)->poll(
                $provider,
                hash('sha256', 'generic-rest-json-path-mapping-failure'),
            );
            self::fail('Expected Generic REST nested mapping failure to stop provider polling.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Generic bank mapped field transaction_id terminal value is not a supported scalar.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('raw-provider-secret-value', $exception->getMessage());
        }

        self::assertSame(0, DB::table('c2c_bank_transactions')->count(), 'Mapping failure must stop before provider evidence ingestion.');
        self::assertSame(0, DB::table('c2c_transaction_matches')->count(), 'Mapping failure must not create a match.');
        self::assertSame(0, DB::table('purchase_settlements')->count(), 'Mapping failure must never reach authoritative purchase capture.');

        $cursor = DB::table('c2c_provider_cursors')->where('provider_code', 'generic_bank')->first();
        self::assertNotNull($cursor);
        self::assertNull($cursor->cursor, 'Failed provider mapping must not advance the provider cursor.');
        self::assertSame(RuntimeException::class, $cursor->last_failure_code);
        self::assertSame(
            1,
            DB::table('c2c_reconciliation_findings')
                ->where('provider_code', 'generic_bank')
                ->where('finding_type', 'provider_cursor_failure')
                ->count(),
            'Mapping failures must use the existing provider reconciliation failure taxonomy.',
        );
    }
}
