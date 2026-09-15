<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Catalog\Application\TelegramTrialClaimSelectionService;
use App\Modules\Catalog\Application\TrialContext;
use App\Modules\Catalog\Application\TrialReservationRequest;
use App\Modules\Catalog\Application\TrialReservationService;
use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialClaim;
use App\Modules\Telegram\Application\TelegramCustomerTrialClaimOptions;
use App\Modules\Telegram\Application\TelegramCustomerTrialClaimReceipt;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramCustomerTrialClaimService implements TelegramCustomerTrialClaim
{
    private const RESERVATION_TTL_MINUTES = 15;

    public function __construct(
        private DatabaseManager $database,
        private TelegramTrialClaimSelectionService $selections,
        private TrialReservationService $trials,
        private OrderSourceAuthorizationService $sourceAuthorizations,
        private NonPaidOrderService $orders,
        private InitialProvisioningQueueService $provisioning,
    ) {}

    public function optionsForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
    ): TelegramCustomerTrialClaimOptions {
        return $this->selections->optionsForSelf(
            $actorUserId,
            $subjectUserId,
            $offeringSelectionToken,
            $routeSelectionToken,
        );
    }

    public function claimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
        ?string $protocolSelectionToken,
        string $operationKey,
        DateTimeImmutable $acceptedAt,
    ): TelegramCustomerTrialClaimReceipt {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Trial claim is restricted to self-service.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new InvalidArgumentException('Telegram Trial claim operation key is invalid.');
        }

        $reservationCommandKey = 'telegram-trial-reserve:'.$operationKey;
        $correlationId = 'tgtrial:'.substr($operationKey, 0, 56);
        $committed = $this->trials->committedReplayForUser($reservationCommandKey, $subjectUserId);
        if ($committed !== null) {
            $this->selections->assertCommittedReplayOfferingTokenForSelf(
                $actorUserId,
                $subjectUserId,
                $offeringSelectionToken,
                $committed->offeringId,
            );
        } else {
            $selection = $this->selections->resolveForSelf(
                $actorUserId,
                $subjectUserId,
                $offeringSelectionToken,
                $routeSelectionToken,
                $protocolSelectionToken,
            );
            $committed = $this->trials->reserveAndCommit(
                new TrialReservationRequest(
                    $selection->offeringId,
                    $subjectUserId,
                    $selection->requestedRouteId,
                    $selection->requestedProtocolProfileId,
                    $acceptedAt->setTimezone(new DateTimeZone('UTC'))->modify('+'.self::RESERVATION_TTL_MINUTES.' minutes'),
                ),
                new TrialContext(
                    $reservationCommandKey,
                    $correlationId,
                    'telegram',
                    'customer_claim_reserve',
                ),
                new TrialContext(
                    'telegram-trial-commit:'.substr(hash('sha256', $reservationCommandKey), 0, 64),
                    $correlationId,
                    'telegram',
                    'customer_claim_commit',
                ),
            );
        }
        if ($committed->state !== 'committed') {
            throw new RuntimeException('Telegram Trial reservation did not commit atomically.');
        }

        try {
            [$authorization, $order, $queue] = $this->database->connection()->transaction(
                function () use ($reservationCommandKey, $correlationId): array {
                    $authorization = $this->sourceAuthorizations->authorizeTrial(
                        $reservationCommandKey,
                        $correlationId,
                    );
                    $order = $this->orders->materialize($authorization->publicId, $correlationId);
                    $queue = $this->provisioning->queueInitial($order->orderPublicId, $correlationId);

                    return [$authorization, $order, $queue];
                },
                3,
            );
        } catch (DomainException $exception) {
            throw new RuntimeException(
                'Committed Telegram Trial downstream handoff failed.',
                previous: $exception,
            );
        }

        if ($committed->userId !== $subjectUserId || $authorization->userId !== $subjectUserId || $order->userId !== $subjectUserId) {
            throw new RuntimeException('Telegram Trial claim actor authority drifted.');
        }
        $presentation = $this->selections->presentationForCommittedSelection(
            $committed->offeringId,
            $committed->routeId,
            $committed->salesServerId,
            $committed->protocolProfileId,
            $committed->fallbackUsed,
            $committed->disclosureFa,
        );

        return new TelegramCustomerTrialClaimReceipt(
            $queue->orderPublicId,
            $queue->serviceSubscriptionPublicId,
            $queue->provisioningOperationPublicId,
            $committed->dataBytes,
            $committed->durationDays,
            $presentation->serverNameFa,
            $presentation->serverNameEn,
            $presentation->protocolNameFa,
            $presentation->protocolNameEn,
            $committed->fallbackUsed,
            $presentation->fallbackDisclosureFa,
            $presentation->fallbackDisclosureEn,
            $committed->replayed || $authorization->replayed || $order->replayed || $queue->replayed,
        );
    }
}
