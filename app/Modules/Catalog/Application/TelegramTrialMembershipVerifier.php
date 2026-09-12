<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluationDecision;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipResolutionRequest;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class TelegramTrialMembershipVerifier implements TrialMembershipVerifier
{
    public function __construct(private TelegramChannelMembershipEvaluator $evaluator) {}

    public function assertSatisfied(Connection $connection, int $userId, int $offeringId, int $policyId): void
    {
        if ($userId < 1 || $offeringId < 1 || $policyId < 1) {
            throw new RuntimeException('Trial membership verification identity must be positive.');
        }
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Trial membership provider verification must run outside a database transaction.');
        }

        try {
            $evaluation = $this->evaluator->evaluate(
                new TelegramChannelMembershipResolutionRequest($userId, 'trial', $offeringId),
            );
        } catch (DomainException|RuntimeException $exception) {
            throw new DomainException('Trial membership verification is unavailable.', previous: $exception);
        }

        if ($evaluation->decision !== TelegramChannelMembershipEvaluationDecision::Satisfied) {
            throw new DomainException('Trial membership requirement is not satisfied.');
        }
    }
}
