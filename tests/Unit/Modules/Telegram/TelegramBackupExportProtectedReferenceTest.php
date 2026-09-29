<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TelegramBackupExportProtectedReferenceTest extends TestCase
{
    /** @requirement BAK-001 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function test_backup_export_reference_round_trips_only_bounded_integrity_identity(): void
    {
        $backupId = '20260929T050500Z-1111111111111111';
        $hash = str_repeat('a', 64);
        $reference = TelegramProtectedPresentationReference::backupExport(
            $backupId,
            'part',
            2,
            4,
            19_000_000,
            $hash,
        );

        $durable = $reference->durableText();
        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v5:backup_export:'.$backupId.':part:2:4:19000000:'.$hash.']',
            $durable,
        );
        self::assertStringNotContainsString('/backups/', $durable);
        self::assertStringNotContainsString('storage', $durable);

        $restored = TelegramProtectedPresentationReference::restore($durable);
        self::assertTrue($restored->isBackupExport());
        self::assertSame($backupId, $restored->publicId);
        self::assertSame([
            'item' => 'part',
            'index' => 2,
            'count' => 4,
            'bytes' => 19_000_000,
            'sha256' => $hash,
        ], $restored->backupExportIdentity());
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_backup_export_reference_rejects_oversized_or_inconsistent_parts(): void
    {
        $backupId = '20260929T050500Z-1111111111111111';
        $hash = str_repeat('b', 64);

        foreach ([
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'part', 0, 2, 10, $hash),
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'manifest', 1, 2, 10, $hash),
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'part', 3, 2, 10, $hash),
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'part', 1, 2, 20_000_001, $hash),
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'part', 1, 10_001, 10, $hash),
            static fn () => TelegramProtectedPresentationReference::backupExport($backupId, 'part', 1, 2, 10, 'not-a-hash'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid backup export reference must be rejected.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
