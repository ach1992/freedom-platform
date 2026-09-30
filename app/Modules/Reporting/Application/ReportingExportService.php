<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use DomainException;

final readonly class ReportingExportService
{
    public function __construct(
        private AdministratorUserPermissionAuthorizer $authorizer,
        private DatabaseReportingSnapshotService $reports,
        private ReportExporter $exporter,
        private ReportValueMasker $masker,
        private ReportingAudit $audit,
    ) {}

    /** @requirement REP-003 ACL-001 ACL-002 SEC-002 */
    public function export(
        int $actorUserId,
        ReportDateRange $range,
        string $format,
        string $correlationId,
        string $requestKey,
    ): ReportExportFile {
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw new DomainException('Report export format is unsupported.');
        }

        $administratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::EXPORT);
        $snapshot = $this->reports->generate(
            $actorUserId,
            $range,
            $correlationId,
            $requestKey.':view',
        );
        $basename = 'report-'.preg_replace('/[^a-z0-9_-]+/i', '-', $range->code).'-'.$range->endsBeforeUtc->format('Ymd-His');
        $dataset = $this->masker->maskDataset($snapshot->exportDataset());
        $file = $format === 'csv'
            ? $this->exporter->csv($dataset, $basename)
            : $this->exporter->xlsx($dataset, $basename);

        $this->audit->recordExport(
            $administratorId,
            $snapshot,
            $file,
            $format,
            $correlationId,
            $requestKey,
        );

        return $file;
    }
}
