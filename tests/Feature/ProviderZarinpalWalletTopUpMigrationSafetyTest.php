<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\PurchaseProviderMutationAttempt;
use App\Modules\Payments\Application\PurchaseProviderMutationBarrier;
use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;
use App\Shared\Domain\Money;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ZarinpalWalletTopUpMigrationTransport implements ZarinpalTransport
{
    public ZarinpalRequestResult $requestResult;

    public function __construct()
    {
        $this->requestResult = ZarinpalRequestResult::accepted('A'.str_repeat('8', 35));
    }

    public function request(
        string $merchantId,
        int $amountIrr,
        string $callbackUrl,
        string $description,
        string $orderId,
    ): ZarinpalRequestResult {
        return $this->requestResult;
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        return ZarinpalVerifyResult::verified('880000001', 100);
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        return [];
    }
}

/** @requirement IPG-001 PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class ProviderZarinpalWalletTopUpMigrationSafetyTest extends TestCase
{
    use DatabaseTruncation;

    private ZarinpalWalletTopUpMigrationTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Zarinpal wallet top-up migration safety requires MariaDB/MySQL.');
        }

        $this->seed();
        $this->seed(WalletFinancialFoundationSeeder::class);
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $this->transport = new ZarinpalWalletTopUpMigrationTransport;
        $this->app->instance(ZarinpalTransport::class, $this->transport);
    }

    public function test_interrupted_table_constraint_install_reenters_under_persistent_provider_fence(): void
    {
        $migration = $this->migration();
        $migration->down();
        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_verifications'));

        $intent = $this->walletTopUpIntent('constraint-interruption');
        $intentId = (int) DB::table('payment_intents')
            ->where('public_id', $intent)
            ->value('id');
        self::assertGreaterThan(0, $intentId);

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected
                || ! str_contains(
                    strtolower($query->sql),
                    'alter table zarinpal_wallet_top_up_verifications add constraint',
                )) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected Zarinpal wallet top-up constraint interruption.');
        });

        try {
            $migration->up();
            self::fail('Constraint failure injection must interrupt the migration.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame(
                'Injected Zarinpal wallet top-up constraint interruption.',
                $exception->getMessage(),
            );
        }

        self::assertTrue(Schema::hasTable('zarinpal_wallet_top_up_verifications'));
        self::assertSame(1, DB::table('zarinpal_wallet_top_up_upgrade_fence')
            ->where('id', 1)
            ->where('active', 1)
            ->count());

        $called = false;
        try {
            $this->app->make(PurchaseProviderMutationBarrier::class)->runForPaymentIntent(
                $intentId,
                'test:zarinpal-wallet-top-up-migration-fence',
                function (PurchaseProviderMutationAttempt $attempt) use (&$called): void {
                    $called = true;
                },
            );
            self::fail('Persistent migration fence must block provider effects before callback entry.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Purchase provider mutation is blocked by an active financial migration.',
                $exception->getMessage(),
            );
        }
        self::assertFalse($called);

        $migration->up();

        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_upgrade_fence'));
        self::assertSame(4, DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'zarinpal_wallet_top_up_verifications')
            ->whereIn('CONSTRAINT_NAME', [
                'zwuv_code_chk',
                'zwuv_money_chk',
                'zwuv_hash_chk',
                'zwuv_reverse_window_chk',
            ])
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->count());
        self::assertTrue($this->triggerExists('zarinpal_wallet_top_up_verifications_insert_guard'));
    }

    public function test_interrupted_shared_guard_replacement_never_drops_authority_and_reenters(): void
    {
        $migration = $this->migration();
        $migration->down();

        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected
                || ! str_contains(
                    strtolower($query->sql),
                    'create or replace trigger zarinpal_verified_unsettled_evidence_insert_guard',
                )) {
                return;
            }

            $injected = true;
            throw new RuntimeException('Injected Zarinpal shared-guard interruption.');
        });

        try {
            $migration->up();
            self::fail('Shared-guard failure injection must interrupt the migration.');
        } catch (RuntimeException $exception) {
            self::assertTrue($injected);
            self::assertSame('Injected Zarinpal shared-guard interruption.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('zarinpal_wallet_top_up_upgrade_fence')
            ->where('id', 1)
            ->where('active', 1)
            ->count());
        foreach ([
            'zarinpal_payment_requests_insert_guard',
            'zarinpal_payment_requests_update_guard',
            'zarinpal_provider_evidence_claims_insert_guard',
            'zarinpal_verified_unsettled_evidence_insert_guard',
            'zarinpal_reconciliation_findings_insert_guard',
        ] as $trigger) {
            self::assertTrue($this->triggerExists($trigger), $trigger.' must never be absent.');
        }

        $migration->up();

        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_upgrade_fence'));
        self::assertStringContainsString(
            'zarinpal_wallet_top_up_verifications',
            $this->triggerAction('zarinpal_reconciliation_findings_insert_guard'),
        );
    }

    public function test_redirectable_wallet_top_up_provider_request_blocks_semantic_rollback(): void
    {
        $intent = $this->walletTopUpIntent('redirectable-rollback');
        $receipt = $this->app->make(ZarinpalPaymentService::class)->initiateWalletTopUp(
            $this->intentUserId($intent),
            $intent,
            $this->correlation('redirectable-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Redirectable, $receipt->state);
        self::assertSame(1, DB::table('zarinpal_payment_requests')
            ->where('payment_intent_id', $this->intentId($intent))
            ->count());

        $this->assertRollbackRejected();
    }

    public function test_uncertain_wallet_top_up_provider_effect_and_reconciliation_attempt_block_rollback(): void
    {
        $intent = $this->walletTopUpIntent('uncertain-rollback');
        $this->transport->requestResult = ZarinpalRequestResult::uncertain();

        $receipt = $this->app->make(ZarinpalPaymentService::class)->initiateWalletTopUp(
            $this->intentUserId($intent),
            $intent,
            $this->correlation('uncertain-initiate'),
        );
        self::assertSame(ZarinpalRequestState::Uncertain, $receipt->state);
        self::assertSame(1, DB::table('purchase_provider_mutation_attempts')
            ->where('payment_intent_id', $this->intentId($intent))
            ->where('state', 'reconciliation_required')
            ->count());

        $this->assertRollbackRejected();
    }

    public function test_pristine_rollback_restores_predecessor_guards_and_cleanly_reenters(): void
    {
        $migration = $this->migration();

        $migration->down();

        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_verifications'));
        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_upgrade_fence'));
        self::assertStringNotContainsString(
            'wallet_top_up',
            $this->triggerAction('zarinpal_payment_requests_insert_guard'),
        );
        self::assertTrue($this->triggerExists('zarinpal_payment_requests_promotion_update_guard'));

        $migration->up();

        self::assertTrue(Schema::hasTable('zarinpal_wallet_top_up_verifications'));
        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_upgrade_fence'));
        self::assertStringContainsString(
            'wallet_top_up',
            $this->triggerAction('zarinpal_payment_requests_insert_guard'),
        );
        self::assertTrue($this->triggerExists('zarinpal_payment_requests_promotion_update_guard'));
    }

    private function assertRollbackRejected(): void
    {
        try {
            $this->migration()->down();
            self::fail('Provider-backed Zarinpal wallet top-up authority must refuse rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back Zarinpal wallet top-up authority while provider-backed wallet top-up or unresolved provider mutation authority exists.',
                $exception->getMessage(),
            );
        }

        self::assertTrue(Schema::hasTable('zarinpal_wallet_top_up_verifications'));
        self::assertFalse(Schema::hasTable('zarinpal_wallet_top_up_upgrade_fence'));
    }

    private function walletTopUpIntent(string $suffix): string
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, $suffix);
        $intent = $this->app->make(WalletTopUpPaymentService::class)->create(
            'zarinpal.wallet.topup.migration.'.$suffix,
            $userId,
            $walletId,
            'zarinpal',
            Money::irr(250_000),
            $this->correlation('intent-'.$suffix),
        );

        return $intent->intentPublicId;
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function wallet(int $userId, string $suffix): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.zarinpal.migration.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function intentId(string $publicId): int
    {
        return (int) DB::table('payment_intents')->where('public_id', $publicId)->value('id');
    }

    private function intentUserId(string $publicId): int
    {
        return (int) DB::table('payment_intents')->where('public_id', $publicId)->value('user_id');
    }

    private function triggerExists(string $trigger): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->exists();
    }

    private function triggerAction(string $trigger): string
    {
        $action = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->value('ACTION_STATEMENT');
        self::assertIsString($action);

        return $action;
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_09_26_000300_enable_zarinpal_wallet_top_up_authority.php',
        );

        return $migration;
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'zarinpal-wallet-top-up-migration:'.$suffix), 0, 64);
    }
}
