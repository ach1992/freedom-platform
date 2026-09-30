<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Reporting\Application\Contracts\ReportingExportDeliveryGateway;
use App\Modules\Reporting\Application\Contracts\ReportingScheduledChannelDelivery;
use App\Modules\Reporting\Application\Contracts\ReportingTextDeliveryGateway;
use DomainException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class ReportingDeliveryService implements ReportingScheduledChannelDelivery
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private DatabaseReportingSnapshotService $reports,
        private ReportTelegramFormatter $formatter,
        private ReportingTextDeliveryGateway $textDelivery,
        private ReportingExportDeliveryGateway $exportDelivery,
        private ReportingAudit $audit,
        private ConfigRepository $config,
    ) {}

    /** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 DAT-003 SEC-002 OPS-003 */
    public function deliverToConfiguredChannel(
        int $actorUserId,
        ReportDateRange $range,
        string $correlationId,
        string $requestKey,
    ): string {
        $administratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::DELIVER);
        $viewAdministratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::VIEW);
        if ($viewAdministratorId !== $administratorId) {
            throw new RuntimeException('Reporting administrator identity changed during authorization.');
        }
        $channelChatId = $this->configuredReportChannelChatId();
        $existingOperationId = $this->textDelivery->findExisting(
            $channelChatId,
            $requestKey.':telegram',
            $correlationId,
        );
        if ($existingOperationId !== null) {
            return $existingOperationId;
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $administratorId,
            $range,
            $channelChatId,
            $correlationId,
            $requestKey,
        ): string {
            $snapshot = $this->reports->generate(
                $actorUserId,
                $range,
                $correlationId,
                $requestKey.':view',
            );
            $operationId = $this->textDelivery->send(
                $channelChatId,
                $this->formatter->format($snapshot),
                $requestKey.':telegram',
                $correlationId,
            );
            $this->audit->recordDelivery(
                $administratorId,
                $operationId,
                [
                    'kind' => 'report_channel',
                    'period' => $range->code,
                    'destination_sha256' => hash('sha256', (string) $channelChatId),
                    'operation_id' => $operationId,
                    'report_sha256' => $snapshot->fingerprint(),
                ],
                $correlationId,
                $requestKey,
            );

            return $operationId;
        }, 3);
    }

    /** @requirement REP-003 ACL-001 ACL-002 DAT-003 SEC-001 SEC-002 OPS-003 */
    public function queueExportForAdministratorChat(
        int $actorUserId,
        int $recipientChatId,
        ReportDateRange $range,
        string $format,
        string $locale,
        string $correlationId,
        string $requestKey,
    ): string {
        if (! in_array($format, ['csv', 'xlsx'], true) || ! in_array($locale, ['fa', 'en'], true)) {
            throw new DomainException('Report export delivery parameters are invalid.');
        }

        $administratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::EXPORT);
        $viewAdministratorId = $this->authorizer->authorizeUser($actorUserId, ReportingPermissions::VIEW);
        if ($viewAdministratorId !== $administratorId) {
            throw new RuntimeException('Reporting administrator identity changed during authorization.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $recipientChatId,
            $range,
            $format,
            $locale,
            $requestKey,
            $correlationId,
            $administratorId,
        ): string {
            $operationId = $this->exportDelivery->queue(
                $recipientChatId,
                $range,
                $format,
                $locale,
                $requestKey.':telegram',
                $correlationId,
            );
            $this->audit->recordDelivery(
                $administratorId,
                $operationId,
                [
                    'kind' => 'private_export',
                    'format' => $format,
                    'period' => $range->code,
                    'destination_sha256' => hash('sha256', (string) $recipientChatId),
                    'operation_id' => $operationId,
                ],
                $correlationId,
                $requestKey,
            );

            return $operationId;
        }, 3);
    }

    private function configuredReportChannelChatId(): int
    {
        $value = $this->config->get('reporting.telegram.report_channel_chat_id');
        if (! is_int($value) || $value === 0) {
            throw new RuntimeException('Reporting Telegram channel is not configured.');
        }

        return $value;
    }
}
