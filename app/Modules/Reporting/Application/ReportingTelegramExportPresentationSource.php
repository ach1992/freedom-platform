<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Telegram\Application\Contracts\TelegramReportingExportPresentationSource;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DateTimeImmutable;
use DomainException;

final readonly class ReportingTelegramExportPresentationSource implements TelegramReportingExportPresentationSource
{
    public function __construct(private ReportingExportService $exports) {}

    /** @requirement REP-003 ACL-001 ACL-002 DAT-003 SEC-001 SEC-002 OPS-003 */
    public function resolveForAdministratorSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if (! $reference->isReportExport()) {
            throw new DomainException('Protected Telegram report-export reference is unavailable.');
        }

        $identity = $reference->reportExportIdentity();
        $range = new ReportDateRange(
            $identity['period'],
            $identity['start_epoch'] === null ? null : new DateTimeImmutable('@'.$identity['start_epoch']),
            new DateTimeImmutable('@'.$identity['end_epoch']),
        );
        $referenceHash = hash('sha256', $reference->durableText());
        $file = $this->exports->export(
            $userId,
            $range,
            $identity['format'],
            'report-export-'.substr($referenceHash, 0, 32),
            'report-export-provider-boundary:'.$referenceHash,
        );

        return ProtectedTelegramPresentation::binaryDocument(
            $file->contents,
            $file->filename,
            sprintf(
                'Report %s (%s), generated at protected delivery boundary.',
                $range->code,
                strtoupper($identity['format']),
            ),
        );
    }
}
