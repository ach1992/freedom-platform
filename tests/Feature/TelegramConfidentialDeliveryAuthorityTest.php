<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\ConfidentialTelegramPresentation;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramConfidentialDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
use App\Modules\Telegram\Application\TelegramInlineCallbackButton;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\TelegramInteractivePresentationTestFactory;
use Tests\TestCase;

final class TelegramConfidentialDeliveryAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramConfidentialDeliveryTestClock $clock;

    private TelegramConfidentialDeliveryTestRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram confidential delivery authority verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();

        $this->clock = new TelegramConfidentialDeliveryTestClock(new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        $this->runtime = new TelegramConfidentialDeliveryTestRuntime('123456');
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(TelegramDeliveryRuntime::class, $this->runtime);
        $this->app->forgetInstance(TelegramInteractionSessionService::class);
        $this->app->forgetInstance(TelegramInteractionCallbackService::class);
    }

    public function test_confidential_and_interactive_surfaces_reach_exact_current_readiness(): void
    {
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady(DB::connection()));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
        self::assertFalse((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(
            DB::connection(),
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
        ));
    }

    public function test_v3_queue_encrypts_confidential_text_and_replay_conflict_binds_plaintext_semantics_without_raw_digest(): void
    {
        $plaintext = 'حساب خصوصی مشتری ۱۲۳';
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText($plaintext);
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900501,
            null,
            $presentation,
            'confidential-request-1',
            'correlation-confidential-1',
        );

        self::assertFalse($created->replayed);
        self::assertSame(TelegramDeliveryOperationState::Prepared, $created->state);

        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        $confidential = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first();
        self::assertNotNull($operation);
        self::assertNotNull($outbox);
        self::assertNotNull($confidential);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $operation->presentation_text);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL, (int) $outbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.$created->publicId.'"}',
            (string) $outbox->payload,
        );
        self::assertNotSame($plaintext, (string) $confidential->presentation_ciphertext);
        self::assertStringNotContainsString($plaintext, (string) $confidential->presentation_ciphertext);
        self::assertSame($this->hasher()->integrityHash($presentation, $created->publicId), (string) $confidential->presentation_hash);
        self::assertNotSame(hash('sha256', $plaintext), (string) $confidential->presentation_hash);
        self::assertStringNotContainsString($plaintext, json_encode((array) $operation, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($plaintext, (string) $outbox->payload);

        $replayed = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900501,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
            'confidential-request-1',
            'correlation-confidential-1',
        );
        self::assertTrue($replayed->replayed);
        self::assertSame($created->publicId, $replayed->publicId);
        self::assertSame(1, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count());

        try {
            ConfidentialTelegramPresentationTestFactory::queue(
                $this->queue(),
                TelegramDeliveryAction::Send,
                900501,
                null,
                ConfidentialTelegramPresentationTestFactory::plainText('changed private account value'),
                'confidential-request-1',
                'correlation-confidential-1',
            );
            self::fail('Changed confidential semantics must conflict on the same request key.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('conflicting semantics', $exception->getMessage());
        }

        $samePlaintextSecondOperation = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900501,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
            'confidential-request-1-second-operation',
            'correlation-confidential-1-second-operation',
        );
        $secondCompanionHash = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $samePlaintextSecondOperation->publicId)
            ->value('presentation_hash');
        self::assertIsString($secondCompanionHash);
        self::assertNotSame((string) $confidential->presentation_hash, $secondCompanionHash);
    }

    public function test_v3_queue_accepts_short_text_even_when_ciphertext_coincidentally_contains_plaintext(): void
    {
        $plaintext = 'e';
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText($plaintext);
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(new TelegramConfidentialDeliveryContainingEncrypter($plaintext)),
            TelegramDeliveryAction::Send,
            900502,
            null,
            $presentation,
            'confidential-short-request-1',
            'correlation-confidential-short-1',
        );

        $confidential = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first(['presentation_ciphertext', 'presentation_hash']);
        self::assertNotNull($confidential);
        self::assertNotSame($plaintext, (string) $confidential->presentation_ciphertext);
        self::assertStringContainsString($plaintext, (string) $confidential->presentation_ciphertext);
        self::assertSame($this->hasher()->integrityHash($presentation, $created->publicId), (string) $confidential->presentation_hash);
        self::assertNotSame(hash('sha256', $plaintext), (string) $confidential->presentation_hash);
    }

    public function test_confidential_encrypter_exception_chain_is_redacted_before_queue_failure_escapes(): void
    {
        $plaintext = 'private-encryption-exception-value';

        try {
            ConfidentialTelegramPresentationTestFactory::queue(
                $this->queue(new TelegramConfidentialDeliveryThrowingEncrypter($plaintext)),
                TelegramDeliveryAction::Send,
                900503,
                null,
                ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
                'confidential-encryption-failure-request-1',
                'correlation-confidential-encryption-failure-1',
            );
            self::fail('Confidential encryption failure must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram confidential presentation could not be encrypted.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($plaintext, (string) $exception);
        }
    }

    public function test_confidential_decrypter_exception_chain_is_redacted_before_executor_failure_escapes(): void
    {
        $plaintext = 'private-decryption-exception-value';
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900504,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
            'confidential-decryption-failure-request-1',
            'correlation-confidential-decryption-failure-1',
        );

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        try {
            $this->executor(
                $transport,
                new TelegramConfidentialDeliveryThrowingEncrypter($plaintext),
            )->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-confidential-decryption-failure-1',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::fail('Confidential decryption failure must fail before provider mutation.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram confidential presentation integrity validation failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($plaintext, (string) $exception);
        }

        self::assertSame(0, $transport->attempts);
        self::assertNull($transport->presentationText);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    public function test_v3_executor_decrypts_only_before_provider_boundary_and_never_sends_marker(): void
    {
        $plaintext = 'private account summary for provider';
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900502,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
            'confidential-request-2',
            'correlation-confidential-2',
        );
        $transport = new TelegramConfidentialDeliveryRecordingTransport;

        $completed = $this->executor($transport)->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-confidential-2',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
        );

        self::assertSame(TelegramDeliveryOperationState::Succeeded, $completed->state);
        self::assertSame(1, $transport->attempts);
        self::assertSame($plaintext, $transport->presentationText);
        self::assertNotSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', $transport->presentationText);
        self::assertSame(0, $transport->transactionLevelDuringMutation);
    }

    public function test_v3_replay_and_provider_resolution_survive_real_application_key_rotation(): void
    {
        $oldConfiguredKey = config('app.key');
        $oldConfiguredPreviousKeys = config('app.previous_keys', []);
        self::assertIsString($oldConfiguredKey);
        self::assertNotSame('', $oldConfiguredKey);
        self::assertIsArray($oldConfiguredPreviousKeys);

        $oldApplicationEncrypter = app('encrypter');
        self::assertInstanceOf(Encrypter::class, $oldApplicationEncrypter);
        $plaintext = 'rotation-safe private account summary';
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText($plaintext);
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900509,
            null,
            $presentation,
            'confidential-request-key-rotation',
            'correlation-confidential-key-rotation',
        );
        $oldCapabilityHash = DB::table('telegram_delivery_authority_capability')
            ->where('id', 1)
            ->value('capability_hash');
        $oldOperation = DB::table('telegram_delivery_operations')
            ->where('public_id', $created->publicId)
            ->first(['request_fingerprint']);
        $oldCompanion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first(['presentation_ciphertext', 'presentation_hash']);
        self::assertIsString($oldCapabilityHash);
        self::assertNotNull($oldOperation);
        self::assertNotNull($oldCompanion);

        $newConfiguredKey = 'base64:'.base64_encode(str_repeat('n', 32));
        self::assertNotSame($oldConfiguredKey, $newConfiguredKey);

        try {
            config([
                'app.key' => $newConfiguredKey,
                'app.previous_keys' => [$oldConfiguredKey],
            ]);
            $this->rebuildApplicationEncryptionKeyring();

            $rotatedCapability = new TelegramDeliveryDatabaseCapability;
            self::assertNotSame($rotatedCapability->expectedHash(), $oldCapabilityHash);
            self::assertNotNull($rotatedCapability->valueMatchingHash($oldCapabilityHash));
            $rotatedEncrypter = app('encrypter');
            self::assertInstanceOf(Encrypter::class, $rotatedEncrypter);
            self::assertCount(2, $rotatedEncrypter->getAllKeys());

            $replayed = ConfidentialTelegramPresentationTestFactory::queue(
                $this->queue(),
                TelegramDeliveryAction::Send,
                900509,
                null,
                ConfidentialTelegramPresentationTestFactory::plainText($plaintext),
                'confidential-request-key-rotation',
                'correlation-confidential-key-rotation',
            );
            self::assertTrue($replayed->replayed);
            self::assertSame($created->publicId, $replayed->publicId);

            $transport = new TelegramConfidentialDeliveryRecordingTransport;
            $completed = $this->executor($transport)->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-confidential-key-rotation',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::assertSame(TelegramDeliveryOperationState::Succeeded, $completed->state);
            self::assertSame($plaintext, $transport->presentationText);
            self::assertSame(1, $transport->attempts);

            $reloadedOperation = DB::table('telegram_delivery_operations')
                ->where('public_id', $created->publicId)
                ->first(['request_fingerprint']);
            $reloadedCompanion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
                ->where('delivery_operation_public_id', $created->publicId)
                ->first(['presentation_ciphertext', 'presentation_hash']);
            self::assertNotNull($reloadedOperation);
            self::assertNotNull($reloadedCompanion);
            self::assertSame((string) $oldOperation->request_fingerprint, (string) $reloadedOperation->request_fingerprint);
            self::assertSame((string) $oldCompanion->presentation_ciphertext, (string) $reloadedCompanion->presentation_ciphertext);
            self::assertSame((string) $oldCompanion->presentation_hash, (string) $reloadedCompanion->presentation_hash);

            $newPresentation = ConfidentialTelegramPresentationTestFactory::plainText('new-key confidential presentation');
            $newCreated = ConfidentialTelegramPresentationTestFactory::queue(
                $this->queue(),
                TelegramDeliveryAction::Send,
                900510,
                null,
                $newPresentation,
                'confidential-request-after-key-rotation',
                'correlation-confidential-after-key-rotation',
            );
            $newCompanion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
                ->where('delivery_operation_public_id', $newCreated->publicId)
                ->first(['presentation_ciphertext', 'presentation_hash']);
            self::assertNotNull($newCompanion);
            self::assertSame(
                $this->hasher()->integrityHash($newPresentation, $newCreated->publicId),
                (string) $newCompanion->presentation_hash,
            );
            self::assertSame(
                'new-key confidential presentation',
                $rotatedEncrypter->decryptString((string) $newCompanion->presentation_ciphertext),
            );
            try {
                $oldApplicationEncrypter->decryptString((string) $newCompanion->presentation_ciphertext);
                self::fail('New confidential ciphertext must not be encrypted with the retired application key.');
            } catch (DecryptException) {
                // Expected: new ciphertext is current-key-only.
            }

            config(['app.previous_keys' => []]);
            $this->rebuildApplicationEncryptionKeyring();
            self::assertNull((new TelegramDeliveryDatabaseCapability)->valueMatchingHash($oldCapabilityHash));

            $blockedTransport = new TelegramConfidentialDeliveryRecordingTransport;
            try {
                $this->executor($blockedTransport)->execute(
                    $newCreated->publicId,
                    $newCreated->outboxEventId,
                    'correlation-confidential-after-key-rotation',
                    TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
                );
                self::fail('Removing the application key required by the durable delivery capability must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('not accepting runtime work', $exception->getMessage());
            }
            self::assertSame(0, $blockedTransport->attempts);
            $this->assertDatabaseHas('telegram_delivery_operations', [
                'public_id' => $newCreated->publicId,
                'state' => 'prepared',
                'provider_attempts' => 0,
            ]);
        } finally {
            config([
                'app.key' => $oldConfiguredKey,
                'app.previous_keys' => $oldConfiguredPreviousKeys,
            ]);
            $this->rebuildApplicationEncryptionKeyring();
        }
    }

    public function test_contract_v3_outbox_handler_dispatches_through_existing_state_machine(): void
    {
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900507,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('confidential outbox dispatch'),
            'confidential-request-dispatch',
            'correlation-confidential-dispatch',
        );
        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        $executor = $this->executor($transport);
        $handler = new TelegramConfidentialDeliveryOutboxHandler(
            static fn (): TelegramDeliveryOperationExecutor => $executor,
        );

        $result = (new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60))->dispatchOne($handler);

        self::assertNotNull($result);
        self::assertSame(3, $handler->contractVersion());
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE, $handler->eventType());
        self::assertSame(1, $transport->attempts);
        self::assertSame('confidential outbox dispatch', $transport->presentationText);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'contract_version' => 3,
            'processed_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function test_container_registers_exact_telegram_delivery_contract_handlers_v1_v2_v3(): void
    {
        $versions = [];
        foreach ($this->app->tagged(OutboxEventHandler::class) as $handler) {
            if ($handler instanceof OutboxEventHandler
                && $handler->eventType() === TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE) {
                $versions[] = $handler->contractVersion();
            }
        }
        sort($versions, SORT_NUMERIC);

        self::assertSame([1, 2, 3], $versions);
    }

    public function test_confidential_plaintext_is_absent_from_common_durable_and_debug_surfaces(): void
    {
        $sentinel = 'PRIVATE_SENTINEL_'.Str::random(32);
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText($sentinel);
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900508,
            null,
            $presentation,
            'confidential-request-plaintext-audit',
            'correlation-confidential-plaintext-audit',
        );

        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        $companion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first();
        self::assertNotNull($operation);
        self::assertNotNull($outbox);
        self::assertNotNull($companion);

        $commonEvidence = json_encode([
            'operation' => (array) $operation,
            'outbox' => (array) $outbox,
            'presentation_string' => (string) $presentation,
            'presentation_debug' => $presentation->__debugInfo(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($sentinel, $commonEvidence);
        self::assertStringNotContainsString($sentinel, (string) $companion->presentation_ciphertext);
        self::assertSame($this->hasher()->integrityHash($presentation, $created->publicId), (string) $companion->presentation_hash);
        self::assertNotSame(hash('sha256', $sentinel), (string) $companion->presentation_hash);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $operation->presentation_text);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.$created->publicId.'"}',
            (string) $outbox->payload,
        );
    }

    public function test_missing_confidential_companion_fails_before_provider_boundary_without_marker_fallback(): void
    {
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900503,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('missing companion must fail closed'),
            'confidential-request-missing',
            'correlation-confidential-missing',
        );
        $this->temporarilyRemoveConfidentialDeleteGuard();
        DB::table('telegram_delivery_confidential_presentations')
            ->where('delivery_operation_public_id', $created->publicId)
            ->delete();
        $this->restoreConfidentialDeleteGuard();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady(DB::connection()));

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        try {
            $this->executor($transport)->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-confidential-missing',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::fail('Missing confidential companion must fail before provider mutation.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }

        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'presentation_text' => '[CONFIDENTIAL_TELEGRAM_PRESENTATION]',
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    public function test_tampered_confidential_hash_fails_before_provider_boundary(): void
    {
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900504,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('tamper-resistant confidential presentation'),
            'confidential-request-tamper',
            'correlation-confidential-tamper',
        );
        $this->temporarilyRemoveConfidentialUpdateGuard();
        DB::table('telegram_delivery_confidential_presentations')
            ->where('delivery_operation_public_id', $created->publicId)
            ->update(['presentation_hash' => str_repeat('a', 64)]);
        $this->restoreConfidentialUpdateGuard();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady(DB::connection()));

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        try {
            $this->executor($transport)->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-confidential-tamper',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::fail('Tampered confidential presentation must fail before provider mutation.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('integrity validation failed', $exception->getMessage());
        }

        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    public function test_persisted_undecryptable_confidential_ciphertext_fails_before_provider_boundary(): void
    {
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900511,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('durable ciphertext corruption must fail closed'),
            'confidential-request-ciphertext-corruption',
            'correlation-confidential-ciphertext-corruption',
        );
        $this->temporarilyRemoveConfidentialUpdateGuard();
        DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->update(['presentation_ciphertext' => 'not-a-valid-laravel-encrypted-envelope']);
        $this->restoreConfidentialUpdateGuard();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady(DB::connection()));

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        try {
            $this->executor($transport)->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-confidential-ciphertext-corruption',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::fail('Undecryptable durable confidential ciphertext must fail before provider mutation.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram confidential presentation integrity validation failed.', $exception->getMessage());
        }

        self::assertSame(0, $transport->attempts);
        self::assertNull($transport->presentationText);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'presentation_text' => '[CONFIDENTIAL_TELEGRAM_PRESENTATION]',
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    public function test_cross_operation_confidential_companion_transplant_fails_before_provider_boundary(): void
    {
        $first = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900512,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('first operation confidential material'),
            'confidential-request-transplant-source',
            'correlation-confidential-transplant-source',
        );
        $second = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900513,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('second operation confidential material'),
            'confidential-request-transplant-target',
            'correlation-confidential-transplant-target',
        );
        $sourceCompanion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $first->publicId)
            ->first(['presentation_ciphertext', 'presentation_hash']);
        self::assertNotNull($sourceCompanion);

        $this->temporarilyRemoveConfidentialUpdateGuard();
        DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $second->publicId)
            ->update([
                'presentation_ciphertext' => (string) $sourceCompanion->presentation_ciphertext,
                'presentation_hash' => (string) $sourceCompanion->presentation_hash,
            ]);
        $this->restoreConfidentialUpdateGuard();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady(DB::connection()));

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        try {
            $this->executor($transport)->execute(
                $second->publicId,
                $second->outboxEventId,
                'correlation-confidential-transplant-target',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            );
            self::fail('Cross-operation confidential companion material must fail before provider mutation.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram confidential presentation integrity validation failed.', $exception->getMessage());
        }

        self::assertSame(0, $transport->attempts);
        self::assertNull($transport->presentationText);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $second->publicId,
            'presentation_text' => '[CONFIDENTIAL_TELEGRAM_PRESENTATION]',
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    public function test_direct_confidential_companion_insert_update_delete_are_rejected(): void
    {
        try {
            DB::table('telegram_delivery_confidential_presentations')->insert([
                'delivery_operation_public_id' => (string) Str::ulid(),
                'presentation_ciphertext' => 'forged-ciphertext',
                'presentation_hash' => str_repeat('a', 64),
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
            ]);
            self::fail('Direct confidential presentation inserts must be rejected.');
        } catch (QueryException) {
            // Expected.
        }

        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900505,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('immutable confidential row'),
            'confidential-request-guards',
            'correlation-confidential-guards',
        );

        try {
            DB::table('telegram_delivery_confidential_presentations')
                ->where('delivery_operation_public_id', $created->publicId)
                ->update(['presentation_hash' => str_repeat('b', 64)]);
            self::fail('Direct confidential presentation updates must be rejected.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('telegram_delivery_confidential_presentations')
                ->where('delivery_operation_public_id', $created->publicId)
                ->delete();
            self::fail('Direct confidential presentation deletes must be rejected.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame(1, DB::table('telegram_delivery_confidential_presentations')
            ->where('delivery_operation_public_id', $created->publicId)
            ->count());
    }

    public function test_v3_confidential_delivery_reuses_v2_keyboard_authority_and_resolves_bearer_only_at_provider_boundary(): void
    {
        $account = $this->account('v3_keyboard', 900506);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.account',
            'summary',
            [],
            'confidential-keyboard-session',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'account.back',
            [],
            'confidential-keyboard-callback',
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton('بازگشت', $callback->publicId, TelegramInlineButtonStyle::Primary)],
        ]);
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('خلاصه محرمانه حساب'),
            'confidential-request-keyboard',
            'correlation-confidential-keyboard',
            $keyboard,
        );

        $snapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first();
        self::assertNotNull($snapshot);
        self::assertStringContainsString($callback->publicId, (string) $snapshot->keyboard_snapshot);
        self::assertStringNotContainsString($callback->token, (string) $snapshot->keyboard_snapshot);

        $transport = new TelegramConfidentialDeliveryRecordingTransport;
        $completed = $this->executor($transport)->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-confidential-keyboard',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
        );

        self::assertSame(TelegramDeliveryOperationState::Succeeded, $completed->state);
        self::assertSame(1, $transport->attempts);
        self::assertNotNull($transport->inlineKeyboardPayload);
        $providerCallback = $transport->inlineKeyboardPayload['inline_keyboard'][0][0]['callback_data'] ?? null;
        self::assertSame($callback->token, $providerCallback);
        self::assertSame('خلاصه محرمانه حساب', $transport->presentationText);
    }

    private function rebuildApplicationEncryptionKeyring(): void
    {
        $this->app->forgetInstance('encrypter');
        $this->app->forgetInstance(TelegramConfidentialPresentationHasher::class);
    }

    private function temporarilyRemoveConfidentialDeleteGuard(): void
    {
        DB::unprepared('DROP TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::DELETE_TRIGGER);
    }

    private function restoreConfidentialDeleteGuard(): void
    {
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        DB::unprepared('CREATE TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::DELETE_TRIGGER
            .' BEFORE DELETE ON '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::deleteTriggerBody());
    }

    private function temporarilyRemoveConfidentialUpdateGuard(): void
    {
        DB::unprepared('DROP TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::UPDATE_TRIGGER);
    }

    private function restoreConfidentialUpdateGuard(): void
    {
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        DB::unprepared('CREATE TRIGGER '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::UPDATE_TRIGGER
            .' BEFORE UPDATE ON '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::updateTriggerBody());
    }

    /** @return array{user_id:int,telegram_account_id:int,telegram_user_id:int} */
    private function account(string $suffix, int $telegramUserId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $telegramAccountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => 'confidential_'.$suffix,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'telegram_account_id' => $telegramAccountId,
            'telegram_user_id' => $telegramUserId,
        ];
    }

    private function sessions(): TelegramInteractionSessionService
    {
        $this->app->forgetInstance(TelegramInteractionSessionService::class);

        return $this->app->make(TelegramInteractionSessionService::class);
    }

    private function callbacks(): TelegramInteractionCallbackService
    {
        $this->app->forgetInstance(TelegramInteractionCallbackService::class);

        return $this->app->make(TelegramInteractionCallbackService::class);
    }

    private function queue(
        ?StringEncrypter $encrypter = null,
        ?TelegramConfidentialPresentationHasher $hasher = null,
    ): TelegramDeliveryQueueService {
        $database = app(DatabaseManager::class);

        return new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock, $encrypter, $hasher),
        );
    }

    private function executor(
        TelegramMutationTransport $transport,
        ?StringEncrypter $encrypter = null,
        ?TelegramConfidentialPresentationHasher $hasher = null,
    ): TelegramDeliveryOperationExecutor {
        return new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock, $encrypter, $hasher),
        );
    }

    private function hasher(): TelegramConfidentialPresentationHasher
    {
        return app(TelegramConfidentialPresentationHasher::class);
    }
}

final readonly class TelegramConfidentialDeliveryTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final readonly class TelegramConfidentialDeliveryTestRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final readonly class TelegramConfidentialDeliveryContainingEncrypter implements StringEncrypter
{
    public function __construct(private string $plaintext) {}

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        if (! hash_equals($this->plaintext, (string) $value)) {
            throw new RuntimeException('Unexpected confidential test plaintext.');
        }

        return 'synthetic-encrypted-envelope-'.$this->plaintext.'-without-plaintext-equality';
    }

    public function decryptString($payload): string
    {
        return $this->plaintext;
    }
}

final readonly class TelegramConfidentialDeliveryThrowingEncrypter implements StringEncrypter
{
    public function __construct(private string $leak) {}

    public function encryptString(#[\SensitiveParameter] $value): string
    {
        throw new RuntimeException('leaky-encrypter: '.$this->leak.' / '.$value);
    }

    public function decryptString($payload): string
    {
        throw new RuntimeException('leaky-encrypter: '.$this->leak.' / '.(string) $payload);
    }
}

final class TelegramConfidentialDeliveryRecordingTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    public ?string $presentationText = null;

    public int $transactionLevelDuringMutation = -1;

    /** @var null|array{inline_keyboard:list<list<array<string,string>>>} */
    public ?array $inlineKeyboardPayload = null;

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        $this->presentationText = $request->presentation instanceof ConfidentialTelegramPresentation
            ? ConfidentialTelegramPresentationTestFactory::rawValue($request->presentation)
            : $request->presentation?->text();
        $this->transactionLevelDuringMutation = DB::connection()->transactionLevel();
        $this->inlineKeyboardPayload = $request->inlineKeyboard?->providerPayload();

        return new TelegramMutationResult(
            TelegramMutationOutcome::Success,
            'telegram_confidential_test_success',
            424242,
        );
    }
}
