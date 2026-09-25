<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramActionMembershipService
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramChannelMembershipEvaluator $evaluator,
        private TelegramActionMembershipRevalidator $revalidator,
    ) {}

    /** @requirement CHN-001 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function forSelf(
        int $actorUserId,
        int $subjectUserId,
        string $action,
        string $locale,
    ): TelegramActionMembershipPreflight {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram action membership self access denied.');
        }
        if (! in_array($locale, ['fa', 'en'], true)) {
            throw new RuntimeException('Telegram action membership locale is invalid.');
        }
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Telegram action membership provider verification must run outside a database transaction.');
        }

        $evaluation = $this->evaluator->evaluate(new TelegramChannelMembershipResolutionRequest(
            $subjectUserId,
            $action,
            null,
        ));
        if ($evaluation->plan->userId !== $subjectUserId
            || $evaluation->plan->action !== $action
            || $evaluation->plan->planOfferingId !== null) {
            throw new TelegramActionMembershipChanged('Telegram action membership evaluation identity changed.');
        }

        $joinReference = $evaluation->decision === TelegramChannelMembershipEvaluationDecision::Unsatisfied
            ? TelegramProtectedPresentationReference::membershipJoinPrompt(
                $action,
                null,
                $evaluation->plan->configurationHash,
                $locale,
            )
            : null;

        return new TelegramActionMembershipPreflight(
            $evaluation->decision,
            $evaluation->plan->configurationHash,
            $action,
            $evaluation->plan->subjectAccountType,
            $joinReference,
        );
    }

    /** @requirement CHN-001 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function assertCurrentForUpdate(
        Connection $connection,
        int $actorUserId,
        int $subjectUserId,
        string $action,
        ?TelegramActionMembershipPreflight $preflight,
    ): void {
        $this->revalidator->assertCurrentForUpdate(
            $connection,
            $actorUserId,
            $subjectUserId,
            $action,
            $preflight,
        );
    }
}
