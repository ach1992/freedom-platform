<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramDeliveryRequestFingerprint;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use PHPUnit\Framework\TestCase;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;

final class TelegramDeliveryRequestFingerprintCompatibilityTest extends TestCase
{
    public function test_v1_fingerprint_remains_byte_compatible_with_pre_v3_base(): void
    {
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            123456789,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('سلام compatibility'),
        );

        self::assertSame(
            '62b1ebcdb04a6d40e15dffae3314e82e25d0ac3b14146348a660f113a1b2bff9',
            TelegramDeliveryRequestFingerprint::make($request, '123456', 'corr-12345678'),
        );
    }

    public function test_v2_fingerprint_remains_byte_compatible_with_pre_v3_base(): void
    {
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            123456789,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('سلام compatibility'),
        );

        self::assertSame(
            '9a6006d59a43a882c2cad51c79554f6a9fe0f2bae2bda7ca89db28d525eefa8c',
            TelegramDeliveryRequestFingerprint::make($request, '123456', 'corr-12345678', str_repeat('a', 64)),
        );
    }

    public function test_v3_fingerprint_binds_keyed_confidential_semantics_without_embedding_plaintext(): void
    {
        $hasher = new TelegramConfidentialPresentationHasher([str_repeat('k', 32)]);
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText('confidential-account-value');
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            123456789,
            null,
            $presentation,
        );
        $fingerprint = TelegramDeliveryRequestFingerprint::make(
            $request,
            '123456',
            'corr-12345678',
            str_repeat('b', 64),
            $hasher->fingerprintHash($presentation),
        );

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);
        $changed = ConfidentialTelegramPresentationTestFactory::plainText('changed-account-value');
        self::assertNotSame(
            $fingerprint,
            TelegramDeliveryRequestFingerprint::make(
                new TelegramMutationRequest(
                    TelegramDeliveryAction::Send,
                    123456789,
                    null,
                    $changed,
                ),
                '123456',
                'corr-12345678',
                str_repeat('b', 64),
                $hasher->fingerprintHash($changed),
            ),
        );
    }

    public function test_v3_previous_key_candidate_preserves_replay_after_application_key_rotation(): void
    {
        $oldKey = str_repeat('o', 32);
        $newKey = str_repeat('n', 32);
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText('rotation-safe-confidential-value');
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            123456789,
            null,
            $presentation,
        );
        $oldHasher = new TelegramConfidentialPresentationHasher([$oldKey]);
        $oldFingerprint = TelegramDeliveryRequestFingerprint::make(
            $request,
            '123456',
            'corr-rotation-123',
            null,
            $oldHasher->fingerprintHash($presentation),
        );

        $rotatedHasher = new TelegramConfidentialPresentationHasher([$newKey, $oldKey]);
        $candidates = TelegramDeliveryRequestFingerprint::candidates(
            $request,
            '123456',
            'corr-rotation-123',
            null,
            $rotatedHasher->fingerprintHashCandidates($presentation),
        );

        self::assertCount(2, $candidates);
        self::assertNotSame($oldFingerprint, $candidates[0]);
        self::assertTrue(TelegramDeliveryRequestFingerprint::matches($oldFingerprint, $candidates));
    }
}
