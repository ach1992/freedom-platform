<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\TelegramOwnedServiceDeliveryResendStatus;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class TelegramOwnedServiceDeliveryResendService implements TelegramOwnedServiceDeliveryResender
{
    private const REASON_CODE = 'telegram.my_services';

    private const REASON = 'Telegram My Services self-service secure resend request.';

    public function __construct(private ServiceDeliveryResendService $resends) {}

    public function resendForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceDeliveryResendStatus {
        try {
            $this->resends->resend(
                $servicePublicId,
                new ServiceDeliveryResendContext(
                    $requestKey,
                    $correlationId,
                    self::REASON_CODE,
                    self::REASON,
                    actorUserId: $actorUserId,
                ),
            );
        } catch (ServiceDeliveryTemporarilyBlockedException) {
            return TelegramOwnedServiceDeliveryResendStatus::TemporarilyBlocked;
        } catch (AuthorizationException|DomainException) {
            return TelegramOwnedServiceDeliveryResendStatus::Unavailable;
        }

        return TelegramOwnedServiceDeliveryResendStatus::Queued;
    }
}
