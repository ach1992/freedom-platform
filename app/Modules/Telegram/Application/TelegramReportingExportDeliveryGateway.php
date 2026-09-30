<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Reporting\Application\Contracts\ReportingExportDeliveryGateway;
use App\Modules\Reporting\Application\ReportDateRange;
use DomainException;

final readonly class TelegramReportingExportDeliveryGateway implements ReportingExportDeliveryGateway
{
    public function __construct(private TelegramReportingProtectedReferenceDeliveryQueue $delivery) {}

    /** @requirement REP-003 DAT-003 SEC-002 OPS-003 */
    public function queue(
        int $recipientChatId,
        ReportDateRange $range,
        string $format,
        string $locale,
        string $requestKey,
        string $correlationId,
    ): string {
        if ($recipientChatId < 1) {
            throw new DomainException('Report export Telegram destination must be a private chat identity.');
        }

        $receipt = $this->delivery->send(
            $recipientChatId,
            TelegramProtectedPresentationReference::reportExport(
                $format,
                $range->code,
                $range->startsAtUtc,
                $range->endsBeforeUtc,
                $locale,
            ),
            $requestKey,
            $correlationId,
        );

        return $receipt->publicId;
    }
}
